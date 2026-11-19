<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Probe;

use Janalis\Tuink\Selector\WidgetTree;
use Revolt\EventLoop;
use Symfony\Component\Tui\Tui;

/**
 * Shares the widget tree of a running application with tuink's process driver.
 *
 * Call it in the application's entry point, before run():
 *
 *     if (class_exists(TuiProbe::class)) {
 *         TuiProbe::install($tui);
 *     }
 *
 * It does nothing unless the process driver started the application: the
 * driver sets TUINK_PROBE to a socket it listens on, and the probe answers its
 * requests from the Revolt event loop. tuink is a dev dependency, hence the
 * class_exists() guard.
 */
final class TuiProbe
{
    public const ENV = 'TUINK_PROBE';

    private string $buffer = '';
    private ?string $callbackId = null;

    /**
     * @param resource $socket
     */
    private function __construct(
        private readonly Tui $tui,
        private $socket,
    ) {
    }

    /**
     * Connects to the driver when TUINK_PROBE names its socket.
     *
     * @return bool Whether the probe is installed
     */
    public static function install(Tui $tui, ?string $path = null): bool
    {
        $path ??= (string) getenv(self::ENV);
        if ('' === $path) {
            return false;
        }

        $socket = @stream_socket_client('unix://'.$path, $code, $message, 2.0);
        if (false === $socket) {
            throw new \RuntimeException(\sprintf('The tuink probe cannot connect to "%s": %s', $path, $message));
        }
        stream_set_blocking($socket, false);

        $probe = new self($tui, $socket);
        $probe->callbackId = EventLoop::onReadable($socket, $probe->read(...));
        // The probe must not keep the loop alive once the application stops
        EventLoop::unreference($probe->callbackId);

        return true;
    }

    private function read(): void
    {
        $data = fread($this->socket, 65536);
        if (false === $data || ('' === $data && feof($this->socket))) {
            $this->close();

            return;
        }

        $this->buffer .= $data;
        while (false !== $end = strpos($this->buffer, "\n")) {
            $line = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 1);
            $this->reply($this->handle($line));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(string $line): array
    {
        try {
            $request = json_decode($line, true, 8, \JSON_THROW_ON_ERROR);
            if (!\is_array($request)) {
                throw new \UnexpectedValueException('A request must be an object.');
            }

            return match ($request['op'] ?? null) {
                'tree' => ['xml' => WidgetTree::fromTui($this->tui)->toXml()],
                'focus' => $this->focus($request['index'] ?? null),
                default => throw new \UnexpectedValueException(\sprintf('Unknown operation "%s".', get_debug_type($request['op'] ?? null))),
            };
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function focus(mixed $index): array
    {
        $widget = \is_int($index) ? (WidgetTree::fromTui($this->tui)->widgets[$index] ?? null) : null;
        if (null === $widget) {
            throw new \OutOfRangeException(\sprintf('No widget has the index %s.', json_encode($index)));
        }
        $this->tui->setFocus($widget);
        $this->tui->requestRender();

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function reply(array $response): void
    {
        $payload = json_encode($response, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE)."\n";
        stream_set_blocking($this->socket, true);
        try {
            for ($written = 0, $length = \strlen($payload); $written < $length; $written += $chunk) {
                $chunk = fwrite($this->socket, substr($payload, $written));
                if (false === $chunk || 0 === $chunk) {
                    $this->close();

                    return;
                }
            }
        } finally {
            if (\is_resource($this->socket)) {
                stream_set_blocking($this->socket, false);
            }
        }
    }

    private function close(): void
    {
        if (null !== $this->callbackId) {
            EventLoop::cancel($this->callbackId);
            $this->callbackId = null;
        }
        if (\is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
