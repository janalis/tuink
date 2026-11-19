<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Driver\TerminalObserver;
use PHPUnit\Framework\TestCase;

final class TerminalObserverTest extends TestCase
{
    public function testBells(): void
    {
        $observer = new TerminalObserver();
        $observer->observe("a\x07b\x07");
        $observer->observe("\x07");

        $this->assertSame(3, $observer->getBellCount());
    }

    public function testABelEndingAStringSequenceDoesNotRing(): void
    {
        $observer = new TerminalObserver();
        $observer->observe("\x1b]0;title\x07\x1b]8;;https://example.com\x07link\x1b]8;;\x07\x1b]7501;x\x1b\\");

        $this->assertSame(0, $observer->getBellCount());
    }

    public function testTitle(): void
    {
        $observer = new TerminalObserver();
        $this->assertNull($observer->getTitle());

        $observer->observe("\x1b]0;first\x07text\x1b]2;second\x1b\\");
        $this->assertSame('second', $observer->getTitle());

        $observer->observe("\x1b]1;icon name\x07");
        // OSC 1 names the icon, not the window.
        $this->assertSame('second', $observer->getTitle());
    }
}
