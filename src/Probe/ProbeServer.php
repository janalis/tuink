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
    /** Random bytes in the name of the private directory the socket lives in */
    private const NAME_BYTES = 6;
    /** Only the user running the tests may reach the socket */
    private const DIRECTORY_MODE = 0o700;
    /** How long each wait for the application lasts before the driver drains its output again */
    private const POLL_SECONDS = 0;
    private const POLL_MICROSECONDS = 5_000;
    private const ACCEPT_TIMEOUT = 0;

    /** @var resource */
    private $server;
    /** @var resource|null */
    private $connection;
    private bool $missing = false;

    /**
     * @param \Closure(): bool $isRunning
     * @param \Closure(): void $idle
     */
    private function __construct(
        private readonly string $directory,
        public readonly string $path,
        private readonly float $timeout,
        private readonly \Closure $isRunning,
        private readonly \Closure $idle,
    ) {
    }

    /**
     * @param float            $timeout   Seconds to wait for the application to connect and answer
     * @param \Closure(): bool $isRunning Whether the application still runs
     * @param \Closure(): void $idle      Keeps the application's output flowing while the driver waits
     * @param string           $parent    Where the socket's private directory goes
     */
    public static function listen(float $timeout, \Closure $isRunning, \Closure $idle, string $parent): self
    {
        $directory = $parent.'/tuink-'.\bin2hex(\random_bytes(self::NAME_BYTES));
        [$created, $warning] = Warnings::capture(static fn (): bool => \mkdir($directory, permissions: self::DIRECTORY_MODE));
        if (!$created) {
            throw new DriverException(\sprintf('Cannot create the probe directory "%s": %s', $directory, $warning ?? 'unknown error'));
        }

        $self = new self($directory, $directory.'/probe.sock', $timeout, $isRunning, $idle);
        [$server, $warning] = Warnings::capture(static fn () => \stream_socket_server('unix://'.$self->path));
        if (false === $server) {
            \rmdir($directory);
            throw new DriverException(\sprintf('Cannot listen on "%s": %s', $self->path, $warning ?? 'unknown error'));
        }
        $self->server = $server;

        return $self;
    }

    /**
     * The application's widget tree, as XML.
     */
    public function tree(): string
    {
        return self::xml($this->ask(['op' => 'tree']));
    }

    /**
     * Gives the focus to a widget, by its index in the tree.
     */
    public function focus(int $index): void
    {
        $this->ask(['op' => 'focus', 'index' => $index]);
    }

    public function close(): void
    {
        $this->disconnect();
        \fclose($this->server);
        \unlink($this->path);
        \rmdir($this->directory);
    }

    /**
     * Sends a request and waits for its answer, a line of JSON.
     *
     * @param array<string, mixed> $request
     */
    private function ask(array $request): mixed
    {
        $deadline = \microtime(true) + $this->timeout;
        $connection = $this->connection($deadline);
        // A dead application fails the write with a warning, and the read below sees the end of the stream
        Warnings::capture(static fn (): int|false => \fwrite($connection, \json_encode($request, \JSON_THROW_ON_ERROR)."\n"));

        while (!self::readable($connection)) {
            if (\microtime(true) >= $deadline) {
                throw new DriverException(\sprintf('The tuink probe did not answer within %.1f seconds.', $this->timeout));
            }
            ($this->idle)();
        }

        $line = \fgets($connection);
        if (false === $line) {
            $this->disconnect();
            throw new DriverException('The tuink probe connection was closed.');
        }

        return self::answer(\json_decode($line, associative: true, flags: \JSON_THROW_ON_ERROR));
    }

    /**
     * The answer, unless it reports an error.
     */
    private static function answer(mixed $decoded): mixed
    {
        if (\is_array($decoded) && \array_key_exists('error', $decoded)) {
            throw new DriverException(\sprintf('The tuink probe failed: %s', \is_string($decoded['error']) ? $decoded['error'] : 'unknown error'));
        }

        return $decoded;
    }

    private static function xml(mixed $answer): string
    {
        if (!\is_array($answer)) {
            throw new DriverException('The tuink probe sent an invalid answer.');
        }
        if (!\is_string($answer['xml'] ?? null)) {
            throw new DriverException('The tuink probe sent no widget tree.');
        }

        return $answer['xml'];
    }

    /**
     * @param resource $connection
     */
    private static function readable($connection): bool
    {
        // A signal interrupting the wait makes stream_select() warn and return false; it counts as a timeout
        [$ready] = Warnings::capture(static function () use ($connection): int|false {
            $read = [$connection];
            $write = null;
            $except = null;

            return \stream_select($read, $write, $except, self::POLL_SECONDS, self::POLL_MICROSECONDS);
        });

        return \is_int($ready) && 0 < $ready;
    }

    /**
     * @return resource
     */
    private function connection(float $deadline)
    {
        if (null !== $this->connection) {
            return $this->connection;
        }
        if ($this->missing) {
            throw self::missing();
        }
        while (true) {
            // With nothing to accept, stream_socket_accept() warns that the wait timed out
            [$connection] = Warnings::capture(fn () => \stream_socket_accept($this->server, timeout: self::ACCEPT_TIMEOUT));
            if (false !== $connection) {
                $this->connection = $connection;

                return $connection;
            }
            if (!($this->isRunning)() || \microtime(true) >= $deadline) {
                // Not connected by now, it never will: later requests fail at once
                $this->missing = true;

                throw self::missing();
            }
            ($this->idle)();
        }
    }

    private static function missing(): UnsupportedDriverException
    {
        return new UnsupportedDriverException(\sprintf(
            'The application did not connect to the tuink probe. CSS selectors on the process driver need it to call %s::install($tui) before run(); or tag the scenario @in_process.',
            TuiProbe::class,
        ));
    }

    private function disconnect(): void
    {
        if (null !== $this->connection) {
            \fclose($this->connection);
        }
        $this->connection = null;
    }
}
