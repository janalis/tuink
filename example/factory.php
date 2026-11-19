<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Janalis\Tuink\Example\DemoApp;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

// The in-process driver calls this with the terminal to build the Tui on
return static fn (VirtualTerminal $terminal): Tui => new DemoApp($terminal)->tui;
