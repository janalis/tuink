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

    public function testNothingToObserve(): void
    {
        $observer = new TerminalObserver();
        $observer->observe('');

        $this->assertSame(0, $observer->getBellCount());
        $this->assertNull($observer->getTitle());
        $this->assertNull($observer->getClipboard());
        $this->assertNull($observer->getMode(25));
        $this->assertNull($observer->getCursorShape());
        $this->assertSame(0, $observer->getKittyQueryCount());
    }

    public function testClipboard(): void
    {
        $observer = new TerminalObserver();
        $observer->observe("\x1b]52;c;".\base64_encode('first')."\x07\x1b]52;;".\base64_encode('a+b/c=')."\x1b\\");
        $this->assertSame('a+b/c=', $observer->getClipboard());

        // A query asks for the clipboard instead of setting it
        $observer->observe("\x1b]52;c;?\x07");
        $this->assertSame('a+b/c=', $observer->getClipboard());

        $observer->observe("\x1b]52;c;\x07");
        $this->assertSame('', $observer->getClipboard());

        // Not base64: padding in the middle
        $observer->observe("\x1b]52;c;Zm9v=YQ\x07");
        $this->assertSame('', $observer->getClipboard());
        // The copy BEL ends the sequence, it does not ring
        $this->assertSame(0, $observer->getBellCount());
    }

    public function testModes(): void
    {
        $observer = new TerminalObserver();
        $observer->observe("\x1b[?1000;1006h\x1b[?25l\x1b[?2004h\x1b[?2004l\x1b[1000h");

        $this->assertTrue($observer->getMode(1000));
        $this->assertTrue($observer->getMode(1006));
        $this->assertFalse($observer->getMode(25));
        $this->assertFalse($observer->getMode(2004));
        $this->assertNull($observer->getMode(1049));

        $observer->observe("\x1b[?1000l\x1b[?25h");
        $this->assertFalse($observer->getMode(1000));
        $this->assertTrue($observer->getMode(25));
        $this->assertTrue($observer->getMode(1006));
    }

    public function testCursorShapes(): void
    {
        $observer = new TerminalObserver();
        $shapes = [];
        foreach (['', '0', '1', '2', '3', '4', '5', '6', '7'] as $parameter) {
            $observer->observe("\x1b[{$parameter} q");
            $shapes[] = $observer->getCursorShape();
        }

        $this->assertSame(['blinking block', 'blinking block', 'blinking block', 'block', 'blinking underline', 'underline', 'blinking bar', 'bar', 'shape 7'], $shapes);

        $observer->observe("\x1b[2 q\x1b[6 q");
        $this->assertSame('bar', $observer->getCursorShape());
    }

    public function testKittyQueries(): void
    {
        $observer = new TerminalObserver();
        $observer->observe("\x1b[?u\x1b[?1u\x1b[?u");
        $observer->observe("\x1b[?u");

        $this->assertSame(3, $observer->getKittyQueryCount());
    }
}
