<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests\Fixtures;

use Janalis\Tuink\Driver\DriverInterface;
use Janalis\Tuink\Driver\TerminalObserver;
use Janalis\Tuink\Launch;
use Symfony\Component\Tui\Terminal\ScreenBuffer;

/**
 * A driver whose process loses the CPU after each pump, as a test runner on a loaded machine does.
 */
final readonly class StarvedDriver implements DriverInterface
{
    /**
     * @param non-negative-int $starvedMicroseconds
     */
    public function __construct(
        private DriverInterface $driver,
        private int $starvedMicroseconds,
    ) {
    }

    public function getName(): string
    {
        return $this->driver->getName();
    }

    public function start(int $columns, int $rows, Launch $launch = new Launch()): void
    {
        $this->driver->start($columns, $rows, $launch);
    }

    public function stop(): void
    {
        $this->driver->stop();
    }

    public function isStarted(): bool
    {
        return $this->driver->isStarted();
    }

    public function isRunning(): bool
    {
        return $this->driver->isRunning();
    }

    public function isInLineMode(): bool
    {
        return $this->driver->isInLineMode();
    }

    public function getExitCode(): ?int
    {
        return $this->driver->getExitCode();
    }

    public function send(string $bytes): void
    {
        $this->driver->send($bytes);
    }

    public function resize(int $columns, int $rows): void
    {
        $this->driver->resize($columns, $rows);
    }

    public function pump(float $seconds): bool
    {
        $wrote = $this->driver->pump($seconds);
        \usleep($this->starvedMicroseconds);

        return $wrote;
    }

    public function getScreen(): ScreenBuffer
    {
        return $this->driver->getScreen();
    }

    public function getObserver(): TerminalObserver
    {
        return $this->driver->getObserver();
    }
}
