<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Probe;

use Janalis\Tuink\Selector\WidgetTree;
use Janalis\Tuink\Util\Warnings;
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

    private string $callbackId;

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
        $path ??= (string) \getenv(self::ENV);
        if ('' === $path) {
            return false;
        }

        [$socket, $warning] = Warnings::capture(static fn () => \stream_socket_client('unix://'.$path, timeout: 2.0));
        if (false === $socket) {
            throw new \RuntimeException(\sprintf('The tuink probe cannot connect to "%s": %s', $path, $warning ?? 'unknown error'));
        }

        $probe = new self($tui, $socket);
        $probe->callbackId = EventLoop::onReadable($socket, $probe->read(...));
        // The probe must not keep the loop alive once the application stops
        EventLoop::unreference($probe->callbackId);

        return true;
    }

    private function read(): void
    {
        // The driver sends one request and waits for its answer, so a readable socket holds one whole line
        $request = \fgets($this->socket);
        if (false === $request) {
            $this->close();

            return;
        }

        $this->reply($this->handle($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(string $line): array
    {
        try {
            $request = self::request(\json_decode($line, associative: true, flags: \JSON_THROW_ON_ERROR));

            return match ($request['op'] ?? null) {
                'tree' => ['xml' => WidgetTree::fromTui($this->tui)->toXml()],
                'focus' => $this->focus($request['index'] ?? null),
                default => throw new \UnexpectedValueException(\sprintf('Unknown operation "%s".', \get_debug_type($request['op'] ?? null))),
            };
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function request(mixed $decoded): array
    {
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('A request must be an object.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function focus(mixed $index): array
    {
        $widget = \is_int($index) ? WidgetTree::fromTui($this->tui)->widgets[$index] ?? null : null;
        if (null === $widget) {
            throw new \OutOfRangeException(\sprintf('No widget has the index %s.', \json_encode($index)));
        }
        $this->tui->setFocus($widget);

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function reply(array $response): void
    {
        $payload = \json_encode($response, \JSON_THROW_ON_ERROR)."\n";
        // If the driver is gone, the write fails with a warning and the next read sees the end of the stream
        Warnings::capture(fn (): int|false => \fwrite($this->socket, $payload));
    }

    private function close(): void
    {
        EventLoop::cancel($this->callbackId);
        \fclose($this->socket);
    }
}
