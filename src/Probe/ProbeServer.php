<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Probe;

use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Exception\UnsupportedDriverException;

/**
 * The process driver's end of the probe: a socket the application's TuiProbe connects to.
 *
 * @internal
 */
final class ProbeServer
{
    /** @var resource */
    private $server;
    /** @var resource|null */
    private $connection;
    private string $buffer = '';

    private function __construct(
        private readonly string $directory,
        public readonly string $path,
    ) {
    }

    public static function listen(): self
    {
        // A private directory: the socket answers anyone who can reach it
        $directory = sys_get_temp_dir().'/tuink-'.bin2hex(random_bytes(6));
        if (!@mkdir($directory, 0o700)) {
            throw new DriverException(\sprintf('Cannot create the probe directory "%s".', $directory));
        }

        $self = new self($directory, $directory.'/probe.sock');
        $server = @stream_socket_server('unix://'.$self->path, $code, $message);
        if (false === $server) {
            @rmdir($directory);
            throw new DriverException(\sprintf('Cannot listen on "%s": %s', $self->path, $message));
        }
        stream_set_blocking($server, false);
        $self->server = $server;

        return $self;
    }

    /**
     * Sends a request and waits for the answer, calling $idle while it waits.
     *
     * @param array<string, mixed> $request
     * @param \Closure(): bool     $isRunning Whether the application still runs
     * @param \Closure(): void     $idle      Keeps the application's output flowing
     *
     * @return array<string, mixed>
     */
    public function request(array $request, float $timeout, \Closure $isRunning, \Closure $idle): array
    {
        $deadline = microtime(true) + $timeout;
        $connection = $this->connection($deadline, $isRunning, $idle);

        if (false === @fwrite($connection, json_encode($request, \JSON_THROW_ON_ERROR)."\n")) {
            $this->disconnect();
            throw new DriverException('The tuink probe connection was lost.');
        }

        while (false === $end = strpos($this->buffer, "\n")) {
            if (microtime(true) >= $deadline) {
                throw new DriverException(\sprintf('The tuink probe did not answer within %.1f seconds.', $timeout));
            }
            $read = [$connection];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 5000) > 0) {
                $data = fread($connection, 65536);
                if (false === $data || ('' === $data && feof($connection))) {
                    $this->disconnect();
                    throw new DriverException('The tuink probe connection was closed.');
                }
                $this->buffer .= $data;
            } else {
                $idle();
            }
        }

        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 1);
        $response = json_decode($line, true, 8, \JSON_THROW_ON_ERROR);
        if (!\is_array($response)) {
            throw new DriverException('The tuink probe sent an invalid answer.');
        }
        if (isset($response['error'])) {
            throw new DriverException(\sprintf('The tuink probe failed: %s', \is_string($response['error']) ? $response['error'] : 'unknown error'));
        }

        return $response;
    }

    public function close(): void
    {
        $this->disconnect();
        if (\is_resource($this->server)) {
            fclose($this->server);
        }
        @unlink($this->path);
        @rmdir($this->directory);
    }

    /**
     * @param \Closure(): bool $isRunning
     * @param \Closure(): void $idle
     *
     * @return resource
     */
    private function connection(float $deadline, \Closure $isRunning, \Closure $idle)
    {
        while (null === $this->connection) {
            $connection = @stream_socket_accept($this->server, 0);
            if (false !== $connection) {
                stream_set_blocking($connection, false);
                $this->connection = $connection;
                break;
            }
            if (!$isRunning() || microtime(true) >= $deadline) {
                throw new UnsupportedDriverException(\sprintf('The application did not connect to the tuink probe. CSS selectors on the process driver need it to call %s::install($tui) before run(); or tag the scenario @in_process.', TuiProbe::class));
            }
            $idle();
        }

        return $this->connection;
    }

    private function disconnect(): void
    {
        if (\is_resource($this->connection)) {
            fclose($this->connection);
        }
        $this->connection = null;
        $this->buffer = '';
    }
}
