<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Starts an application with the process driver, writes its pid to the file named by the first argument, then waits
// to be killed without stopping it.

use Janalis\Tuink\Driver\ProcessDriver;

require \dirname(__DIR__, levels: 2).'/vendor/autoload.php';

// The application shows its pid, which exec keeps, and ignores SIGTERM
$driver = new ProcessDriver(['/bin/sh', '-c', 'echo "pid $$."; trap "" TERM; while :; do sleep 1; done']);
$driver->start(20, rows: 5);
$pid = [];
while (1 !== \preg_match('/pid (\d+)\./', $driver->getScreen()->getScreen(), $pid)) {
    $driver->pump(0.05);
}
// The line break tells the test the pid is whole
\file_put_contents($argv[1], $pid[1]."\n");

\sleep(60);
