<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Probe;

use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Exception\UnsupportedDriverException;
use Janalis\Tuink\Util\Warnings;

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
    ) {}

    public static function listen(): self
    {
        // A private directory: the socket answers anyone who can reach it
        $directory = \sys_get_temp_dir().'/tuink-'.\bin2hex(\random_bytes(6));
        [$created, $warning] = Warnings::capture(static fn (): bool => \mkdir($directory, permissions: 0o700));
        if (!$created) {
            throw new DriverException(\sprintf('Cannot create the probe directory "%s": %s', $directory, $warning ?? 'unknown error'));
        }

        $self = new self($directory, $directory.'/probe.sock');
        [$server, $warning] = Warnings::capture(static fn () => \stream_socket_server('unix://'.$self->path));
        if (false === $server) {
            \rmdir($directory);
            throw new DriverException(\sprintf('Cannot listen on "%s": %s', $self->path, $warning ?? 'unknown error'));
        }
        \stream_set_blocking($server, enable: false);
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
     * @return array<array-key, mixed>
     */
    public function request(array $request, float $timeout, \Closure $isRunning, \Closure $idle): array
    {
        $deadline = \microtime(true) + $timeout;
        $connection = $this->connection($deadline, $isRunning, $idle);

        [$written] = Warnings::capture(static fn (): int|false => \fwrite($connection, \json_encode($request, \JSON_THROW_ON_ERROR)."\n"));
        if (false === $written) {
            $this->disconnect();
            throw new DriverException('The tuink probe connection was lost.');
        }

        while (false === ($end = \strpos($this->buffer, needle: "\n"))) {
            if (\microtime(true) >= $deadline) {
                throw new DriverException(\sprintf('The tuink probe did not answer within %.1f seconds.', $timeout));
            }
            // A signal interrupting the wait makes stream_select() warn and return false; it is retried like a timeout
            [$ready] = Warnings::capture(static function () use ($connection): int|false {
                $read = [$connection];
                $write = null;
                $except = null;

                return \stream_select($read, $write, $except, seconds: 0, microseconds: 5000);
            });
            if (!$ready) {
                $idle();

                continue;
            }
            $data = \fread($connection, length: 65_536);
            if (false === $data || ('' === $data && \feof($connection))) {
                $this->disconnect();
                throw new DriverException('The tuink probe connection was closed.');
            }
            $this->buffer .= $data;
        }

        $line = \substr($this->buffer, offset: 0, length: $end);
        $this->buffer = \substr($this->buffer, $end + 1);
        $response = self::answer(\json_decode($line, associative: true, depth: 8, flags: \JSON_THROW_ON_ERROR));
        if (\array_key_exists('error', $response)) {
            throw new DriverException(\sprintf('The tuink probe failed: %s', \is_string($response['error']) ? $response['error'] : 'unknown error'));
        }

        return $response;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function answer(mixed $decoded): array
    {
        if (!\is_array($decoded)) {
            throw new DriverException('The tuink probe sent an invalid answer.');
        }

        return $decoded;
    }

    public function close(): void
    {
        $this->disconnect();
        if (\is_resource($this->server)) {
            \fclose($this->server);
        }
        if (\file_exists($this->path)) {
            \unlink($this->path);
        }
        if (\is_dir($this->directory)) {
            \rmdir($this->directory);
        }
    }

    /**
     * @param \Closure(): bool $isRunning
     * @param \Closure(): void $idle
     *
     * @return resource
     */
    private function connection(float $deadline, \Closure $isRunning, \Closure $idle)
    {
        if (null !== $this->connection) {
            return $this->connection;
        }
        while (true) {
            // With nothing to accept, stream_socket_accept() warns that the wait timed out
            [$connection] = Warnings::capture(fn () => \stream_socket_accept($this->server, timeout: 0));
            if (false !== $connection) {
                \stream_set_blocking($connection, enable: false);
                $this->connection = $connection;

                return $connection;
            }
            if (!$isRunning() || \microtime(true) >= $deadline) {
                throw new UnsupportedDriverException(\sprintf(
                    'The application did not connect to the tuink probe. CSS selectors on the process driver need it to call %s::install($tui) before run(); or tag the scenario @in_process.',
                    TuiProbe::class,
                ));
            }
            $idle();
        }
    }

    private function disconnect(): void
    {
        if (\is_resource($this->connection)) {
            \fclose($this->connection);
        }
        $this->connection = null;
        $this->buffer = '';
    }
}
