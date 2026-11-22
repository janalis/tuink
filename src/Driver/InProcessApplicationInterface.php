<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Driver;

use Symfony\Component\Tui\Tui;

/**
 * An application whose entry point does more than Tui::run(): what it sets up
 * before the loop runs, and tears down after it returns.
 *
 * An in-process factory may return one instead of a bare Tui. The driver calls
 * boot() before it starts the Tui, as an entry point does before run(), and
 * shutdown() once the Tui stopped, as the code after run() would: as soon as
 * it quits by itself, or when the driver stops it, before the driver cancels
 * what the application left on the event loop.
 */
interface InProcessApplicationInterface
{
    /**
     * The Tui, built on the VirtualTerminal the factory was given.
     */
    public function getTui(): Tui;

    /**
     * Sets the application up; runs before the Tui starts.
     */
    public function boot(): void;

    /**
     * Tears the application down; runs after the Tui stopped, even when a step failed.
     */
    public function shutdown(): void;
}
