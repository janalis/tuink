<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Driver\OutputBuffer;
use Janalis\Tuink\Example\DemoApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\TextWidget;

final class OutputBufferTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function chunks(): iterable
    {
        yield 'plain' => ['abc', 'abc', ''];
        yield 'lone escape' => ["ab\x1b", 'ab', "\x1b"];
        yield 'csi without final' => ["ab\x1b[38;5;23", 'ab', "\x1b[38;5;23"];
        yield 'csi complete' => ["ab\x1b[38;5;233m", "ab\x1b[38;5;233m", ''];
        yield 'csi then text' => ["\x1b[2Jab", "\x1b[2Jab", ''];
        yield 'ss3 cut' => ["a\x1bO", 'a', "\x1bO"];
        yield 'ss3 complete' => ["a\x1bOP", "a\x1bOP", ''];
        yield 'utf-8 lead byte f7 cut' => ["a\xF7\x80", 'a', "\xF7\x80"];
        yield 'osc open' => ["a\x1b]0;title", 'a', "\x1b]0;title"];
        yield 'osc open across a line' => ["a\x1b]0;ti\ntle", 'a', "\x1b]0;ti\ntle"];
        yield 'osc bel' => ["a\x1b]0;title\x07b", "a\x1b]0;title\x07b", ''];
        yield 'osc st' => ["a\x1b]0;title\x1b\\b", "a\x1b]0;title\x1b\\b", ''];
        yield 'osc with split st' => ["a\x1b]0;title\x1b", 'a', "\x1b]0;title\x1b"];
        yield 'osc then csi cut' => ["a\x1b]0;t\x07b\x1b[3", "a\x1b]0;t\x07b", "\x1b[3"];
        yield 'two-byte escape' => ["a\x1b7", "a\x1b7", ''];
        yield 'utf-8 cut after lead' => ["a\xE2", 'a', "\xE2"];
        yield 'utf-8 cut inside' => ["a\xE2\x94", 'a', "\xE2\x94"];
        yield 'utf-8 complete' => ["a\xE2\x94\x80", "a\xE2\x94\x80", ''];
        yield 'four bytes cut' => ["a\xF0\x9F\x98", 'a', "\xF0\x9F\x98"];
    }

    #[DataProvider('chunks')]
    public function testFeedKeepsBackTheIncompleteTail(string $data, string $complete, string $pending): void
    {
        $buffer = new OutputBuffer();

        $this->assertSame($complete, $buffer->feed($data));
        $this->assertSame($pending, $buffer->flush());
    }

    public function testThePendingTailJoinsTheNextChunk(): void
    {
        $buffer = new OutputBuffer();

        $this->assertSame('a', $buffer->feed("a\x1b[38;5"));
        $this->assertSame("\x1b[38;5;233mb", $buffer->feed(';233mb'));
        $this->assertSame('', $buffer->flush());
    }

    /**
     * Whatever the cut, the screen comes out as from one write.
     */
    public function testAFrameSplitAnywhereDrawsTheSameScreen(): void
    {
        $terminal = new VirtualTerminal(40, 12);
        $tui = new DemoApp($terminal)->tui;
        $tui->add(new TextWidget('Styled ─ é 😀')->setStyle(new Style(color: Color::hex('#336699'), bold: true)));
        $tui->start();
        $tui->processRender();
        $frame = $terminal->consumeOutput();
        $tui->stop();

        $whole = new ScreenBuffer(40, 12);
        $whole->write($frame);
        $this->assertStringContainsString('Styled ─ é 😀', $whole->getScreen());

        for ($cut = 1, $length = \strlen($frame); $cut < $length; ++$cut) {
            $buffer = new OutputBuffer();
            $split = new ScreenBuffer(40, 12);
            $split->write($buffer->feed(\substr($frame, offset: 0, length: $cut)));
            $split->write($buffer->feed(\substr($frame, $cut)));
            $split->write($buffer->flush());

            $this->assertSame($whole->getStyledScreen(), $split->getStyledScreen(), \sprintf('Cut at byte %d.', $cut));
        }
    }
}
