<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink;

use Janalis\Tuink\Driver\DriverInterface;
use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Exception\ExpectationException;
use Janalis\Tuink\Exception\UnsupportedDriverException;

/**
 * Drives one application run and asserts on what it shows.
 *
 * Every action settles afterwards: it lets the application work until its
 * output has been quiet for a moment. Assertions wait too, retrying until
 * the timeout, so a frame that comes late does not fail a step.
 */
final class Session
{
    public function __construct(
        private readonly DriverInterface $driver,
        private int $columns = 80,
        private int $rows = 24,
        private readonly float $timeout = 2.0,
        private readonly float $quietPeriod = 0.05,
        private readonly ?string $snapshotDir = null,
    ) {
    }

    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }

    public function getColumns(): int
    {
        return $this->columns;
    }

    public function getRows(): int
    {
        return $this->rows;
    }

    public function start(?int $columns = null, ?int $rows = null): void
    {
        $this->columns = $columns ?? $this->columns;
        $this->rows = $rows ?? $this->rows;
        $this->driver->start($this->columns, $this->rows);
        $this->settle(true);
    }

    public function stop(): void
    {
        if ($this->driver->isStarted()) {
            $this->driver->stop();
        }
    }

    public function isStarted(): bool
    {
        return $this->driver->isStarted();
    }

    /**
     * Types text literally, as characters.
     */
    public function type(string $text): void
    {
        $this->ensureRunning();
        $this->driver->send($text);
        $this->settle();
    }

    /**
     * Presses a key, like "Enter", "Ctrl+S" or "Shift+Tab", one or more times.
     */
    public function press(string $key, int $times = 1): void
    {
        $bytes = Keys::key($key);
        for ($i = 0; $i < $times; ++$i) {
            $this->ensureRunning();
            $this->driver->send($bytes);
            $this->driver->pump(0.0);
        }
        $this->settle();
    }

    /**
     * Sends a text mixing characters and key names, like "abc<Enter>".
     */
    public function send(string $text): void
    {
        $this->ensureRunning();
        $this->driver->send(Keys::sequence($text));
        $this->settle();
    }

    /**
     * Pastes text as a terminal does with bracketed paste.
     */
    public function paste(string $text): void
    {
        $this->ensureRunning();
        $this->driver->send("\x1b[200~".$text."\x1b[201~");
        $this->settle();
    }

    public function resize(int $columns, int $rows): void
    {
        $this->ensureRunning();
        $this->columns = $columns;
        $this->rows = $rows;
        $this->driver->resize($columns, $rows);
        $this->settle(true);
    }

    /**
     * Lets the application run until its output has been quiet for a moment, or the timeout passed.
     *
     * @param bool $awaitOutput Wait for a first write before counting the quiet time
     */
    public function settle(bool $awaitOutput = false): void
    {
        $deadline = microtime(true) + $this->timeout;
        $lastWrite = $awaitOutput ? null : microtime(true);

        while (microtime(true) < $deadline) {
            if ($this->driver->pump(0.005)) {
                $lastWrite = microtime(true);
            } elseif (!$this->driver->isRunning()) {
                return;
            } elseif (null !== $lastWrite && microtime(true) - $lastWrite >= $this->quietPeriod) {
                return;
            }
        }
    }

    /**
     * The screen as plain text, without trailing spaces or trailing blank lines.
     */
    public function getScreenText(): string
    {
        return $this->driver->getScreen()->getScreen();
    }

    /**
     * @return list<string> Every row of the screen, without trailing spaces
     */
    public function getLines(): array
    {
        return array_values(array_map(rtrim(...), $this->driver->getScreen()->getLines()));
    }

    /**
     * One row, numbered from 1.
     */
    public function getLine(int $number): string
    {
        $lines = $this->getLines();
        if ($number < 1 || $number > \count($lines)) {
            throw new \OutOfRangeException(\sprintf('The screen has %d lines, there is no line %d.', \count($lines), $number));
        }

        return $lines[$number - 1];
    }

    /**
     * Waits until $condition holds, or fails with $message and the screen.
     *
     * @param callable(self): bool      $condition
     * @param string|\Closure(): string $message   Built at failure time when a closure, to describe the final state
     */
    public function waitUntil(callable $condition, string|\Closure $message, ?float $timeout = null): void
    {
        $deadline = microtime(true) + ($timeout ?? $this->timeout);

        while (true) {
            if ($condition($this)) {
                return;
            }
            if (microtime(true) >= $deadline) {
                throw new ExpectationException(\is_string($message) ? $message : $message(), $this->dump());
            }
            if ($this->driver->isStarted()) {
                $this->driver->pump(0.01);
            }
        }
    }

    public function assertSee(string $text): void
    {
        $this->waitUntil(
            static fn (self $s): bool => str_contains($s->getScreenText(), $text),
            \sprintf('The text "%s" was not found on the screen.', $text),
        );
    }

    public function assertNotSee(string $text): void
    {
        $this->settle();
        $this->waitUntil(
            static fn (self $s): bool => !str_contains($s->getScreenText(), $text),
            \sprintf('The text "%s" was found on the screen.', $text),
        );
    }

    public function assertSeeMatch(string $pattern): void
    {
        $this->waitUntil(
            static fn (self $s): bool => 1 === preg_match($pattern, $s->getScreenText()),
            \sprintf('No text on the screen matches %s.', $pattern),
        );
    }

    public function assertLineContains(int $number, string $text): void
    {
        $this->waitUntil(
            static fn (self $s): bool => str_contains($s->lineOrEmpty($number), $text),
            \sprintf('Line %d does not contain "%s".', $number, $text),
        );
    }

    public function assertLineEquals(int $number, string $text): void
    {
        $this->waitUntil(
            static fn (self $s): bool => $s->lineOrEmpty($number) === rtrim($text),
            \sprintf('Line %d is not "%s".', $number, $text),
        );
    }

    /**
     * Compares the whole screen, ignoring trailing spaces and trailing blank lines.
     */
    public function assertScreenEquals(string $expected): void
    {
        $expected = self::normalize($expected);
        $this->waitUntil(
            static fn (self $s): bool => self::normalize($s->getScreenText()) === $expected,
            "The screen is not:\n".self::number($expected),
        );
    }

    /**
     * Compares the screen with a stored snapshot, writing it when missing or when TUINK_UPDATE_SNAPSHOTS is set.
     *
     * A missing snapshot fails instead when the CI environment variable is set.
     */
    public function assertMatchesSnapshot(string $name): void
    {
        if (null === $this->snapshotDir) {
            throw new \LogicException('No snapshot directory is configured.');
        }
        if (!preg_match('/^[\w.\- \/]+$/', $name) || str_contains($name, '..')) {
            throw new \InvalidArgumentException(\sprintf('Invalid snapshot name "%s".', $name));
        }

        $path = $this->snapshotDir.'/'.$name.'.txt';
        $update = '' !== (string) getenv('TUINK_UPDATE_SNAPSHOTS') && '0' !== getenv('TUINK_UPDATE_SNAPSHOTS');

        if ($update || !is_file($path)) {
            if (!$update && '' !== (string) getenv('CI')) {
                throw new ExpectationException(\sprintf('The snapshot "%s" does not exist; record it locally and commit it.', $path), $this->dump());
            }
            $this->settle();
            if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0o777, true) && !is_dir(\dirname($path))) {
                throw new \RuntimeException(\sprintf('Cannot create the snapshot directory "%s".', \dirname($path)));
            }
            file_put_contents($path, self::normalize($this->getScreenText())."\n");

            return;
        }

        $this->assertScreenEquals((string) file_get_contents($path));
    }

    /**
     * Waits for the application to exit, and checks its exit code when one is given.
     */
    public function assertExited(?int $code = null): void
    {
        $this->waitUntil(
            fn (): bool => !$this->driver->isRunning(),
            'The application is still running.',
        );

        if (null === $code) {
            return;
        }

        $actual = $this->driver->getExitCode() ?? throw new UnsupportedDriverException(\sprintf('The "%s" driver does not know exit codes.', $this->driver->getName()));
        if ($actual !== $code) {
            throw new ExpectationException(\sprintf('The application exited with code %d, not %d.', $actual, $code), $this->dump());
        }
    }

    /**
     * The screen with row numbers, for failure messages.
     */
    public function dump(): string
    {
        if (!$this->driver->isStarted() && !$this->hasScreen()) {
            return 'The application is not started.';
        }

        $state = $this->driver->isRunning() ? 'running' : 'exited';
        $text = $this->getScreenText();

        return \sprintf("Screen (%dx%d, %s driver, %s):\n%s", $this->columns, $this->rows, $this->driver->getName(), $state, '' === $text ? '    (blank)' : self::number($text));
    }

    private function lineOrEmpty(int $number): string
    {
        return $this->getLines()[$number - 1] ?? '';
    }

    private function hasScreen(): bool
    {
        try {
            $this->driver->getScreen();

            return true;
        } catch (DriverException) {
            return false;
        }
    }

    private function ensureRunning(): void
    {
        if (!$this->driver->isStarted()) {
            throw new DriverException('The application is not started.');
        }
        if (!$this->driver->isRunning()) {
            throw new ExpectationException('The application is no longer running.', $this->dump());
        }
    }

    private static function normalize(string $text): string
    {
        $lines = array_map(rtrim(...), explode("\n", str_replace("\r\n", "\n", $text)));
        while ([] !== $lines && '' === end($lines)) {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    private static function number(string $text): string
    {
        $lines = explode("\n", $text);
        $width = \strlen((string) \count($lines));
        $out = [];
        foreach ($lines as $i => $line) {
            $out[] = \sprintf('%'.$width.'d | %s', $i + 1, $line);
        }

        return implode("\n", $out);
    }
}
