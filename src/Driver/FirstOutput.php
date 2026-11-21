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
 * Waits for a started application's first output, which it writes once it has taken the terminal over: keys sent
 * before reach a terminal still in line mode, which echoes them and reads Ctrl+S as a pause of the output.
 *
 * @internal
 */
final class FirstOutput
{
    /**
     * Returns once the application wrote or exited; an application still silent after $timeout seconds is stopped.
     *
     * @throws DriverException When the application wrote nothing within $timeout seconds
     */
    public static function await(DriverInterface $driver, float $timeout): void
    {
        $deadline = \microtime(true) + $timeout;

        while (!$driver->pump(0.01) && $driver->isRunning()) {
            if (\microtime(true) < $deadline) {
                continue;
            }
            $driver->stop();

            throw new DriverException(\sprintf('The application has written nothing to its terminal within %.1f seconds of its start; raise start_timeout if it starts this slowly.', $timeout));
        }
    }
}
