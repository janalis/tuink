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
use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Launch;
use Symfony\Component\Tui\Terminal\ScreenBuffer;

/**
 * A driver whose output the test writes, and whose input it reads.
 */
final class FakeDriver implements DriverInterface
{
    public ?Launch $launch = null;
    public string $input = '';
    public int $starts = 0;
    private ?ScreenBuffer $screen = null;
    private TerminalObserver $observer;
    /** Whether the next pump reports output: an application writes its terminal modes once it starts */
    private bool $started = false;

    public function __construct()
    {
        $this->observer = new TerminalObserver();
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function start(int $columns, int $rows, Launch $launch = new Launch()): void
    {
        if (null !== $this->screen) {
            throw new DriverException('The application is already started.');
        }
        ++$this->starts;
        $this->launch = $launch;
        $this->screen = new ScreenBuffer($columns, $rows);
        $this->observer = new TerminalObserver();
        $this->started = true;
    }

    public function stop(): void
    {
        $this->screen = null;
    }

    public function isStarted(): bool
    {
        return null !== $this->screen;
    }

    public function isRunning(): bool
    {
        return null !== $this->screen;
    }

    public function getExitCode(): ?int
    {
        return null;
    }

    public function send(string $bytes): void
    {
        $this->input .= $bytes;
    }

    public function resize(int $columns, int $rows): void
    {
        $this->screen = new ScreenBuffer($columns, $rows);
    }

    public function pump(float $seconds): bool
    {
        $wrote = $this->started;
        $this->started = false;

        return $wrote;
    }

    public function getScreen(): ScreenBuffer
    {
        return $this->screen ?? throw new DriverException('The application is not started.');
    }

    public function getObserver(): TerminalObserver
    {
        return $this->observer;
    }

    /**
     * What the application writes.
     */
    public function output(string $bytes): void
    {
        $this->getScreen()->write($bytes);
        $this->observer->observe($bytes);
    }
}
