<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Driver;

use Janalis\Tuink\Launch;
use Symfony\Component\Tui\Terminal\ScreenBuffer;

/**
 * Runs a Tui application and exposes what it draws.
 *
 * A driver owns one run of the application: start() boots it in a terminal of
 * the given size, send() feeds raw bytes as if typed, and pump() lets the
 * application work for a while and folds what it wrote into the screen.
 */
interface DriverInterface
{
    public function getName(): string;

    /**
     * Boots the application with the scenario's arguments, environment and workspace.
     */
    public function start(int $columns, int $rows, Launch $launch = new Launch()): void;

    /**
     * Stops the application if it still runs and releases its resources.
     */
    public function stop(): void;

    public function isStarted(): bool;

    /**
     * Whether the application is still running; false once it quit by itself.
     */
    public function isRunning(): bool;

    /**
     * The exit code once the application exited, null while it runs or when the driver cannot know it.
     */
    public function getExitCode(): ?int;

    /**
     * Sends raw bytes to the application's input.
     */
    public function send(string $bytes): void;

    public function resize(int $columns, int $rows): void;

    /**
     * Lets the application run for up to $seconds and folds its output into the screen.
     *
     * @return bool Whether the application wrote anything
     */
    public function pump(float $seconds): bool;

    public function getScreen(): ScreenBuffer;

    /**
     * What the output showed besides cells: bells, the title, the clipboard, modes and the cursor shape.
     */
    public function getObserver(): TerminalObserver;
}
