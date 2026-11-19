<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Example\DemoApp;
use Janalis\Tuink\Probe\TuiProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class TuiProbeTest extends TestCase
{
    public function testDoesNothingOutsideTheProcessDriver(): void
    {
        putenv(TuiProbe::ENV);

        $this->assertFalse(TuiProbe::install((new DemoApp(new VirtualTerminal()))->tui));
    }

    public function testFailsLoudlyWhenTheSocketIsGone(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The tuink probe cannot connect to');

        TuiProbe::install((new DemoApp(new VirtualTerminal()))->tui, sys_get_temp_dir().'/tuink-missing-'.bin2hex(random_bytes(4)).'.sock');
    }
}
