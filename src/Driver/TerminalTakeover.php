<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Driver;

use Janalis\Tuink\Exception\DriverException;

/**
 * Waits for a started application to take its terminal over: keys sent before reach a terminal still in line mode,
 * which echoes them and reads Ctrl+S as a pause of the output. Output alone does not tell, as a warning PHP writes
 * while it boots comes before.
 *
 * @internal
 */
final class TerminalTakeover
{
    /**
     * Returns once the application left line mode, or exited. One that wrote but stays in line mode for $timeout
     * seconds reads lines, and goes on; one still silent then is stopped. A timeout of 0 does not wait.
     *
     * @throws DriverException When the application neither wrote nor left line mode within $timeout seconds
     */
    public static function await(DriverInterface $driver, float $timeout): void
    {
        if ($timeout <= 0.0) {
            return;
        }
        $deadline = \microtime(true) + $timeout;
        $wrote = false;

        while ($driver->isRunning()) {
            // Each look at the mode runs stty: a pump in between keeps them few
            $wrote = $driver->pump(0.05) || $wrote;
            if (!$driver->isInLineMode()) {
                return;
            }
            if (\microtime(true) < $deadline) {
                continue;
            }
            if ($wrote) {
                return;
            }
            $driver->stop();

            throw new DriverException(\sprintf(
                'The application has neither written to its terminal nor taken it over within %.1f seconds of its start; raise start_timeout if it starts this slowly.',
                $timeout,
            ));
        }
    }
}
