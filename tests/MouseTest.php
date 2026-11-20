<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Driver\InProcessDriver;
use Janalis\Tuink\Exception\ExpectationException;
use Janalis\Tuink\Mouse;
use Janalis\Tuink\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\TextWidget;

final class MouseTest extends TestCase
{
    /** @var list<string> */
    private array $received = [];
    private ?Session $session = null;

    protected function tearDown(): void
    {
        $this->session?->stop();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reports(): iterable
    {
        yield 'left click' => [Mouse::click(3, column: 7), "\x1b[<0;7;3M\x1b[<0;7;3m"];
        yield 'right click' => [Mouse::click(1, column: 1, button: Mouse::RIGHT), "\x1b[<2;1;1M\x1b[<2;1;1m"];
        yield 'middle click' => [Mouse::click(2, column: 4, button: 'Middle'), "\x1b[<1;4;2M\x1b[<1;4;2m"];
        yield 'double click' => [Mouse::click(1, column: 2, count: 2), "\x1b[<0;2;1M\x1b[<0;2;1m\x1b[<0;2;1M\x1b[<0;2;1m"];
        yield 'shift alt ctrl' => [Mouse::click(1, column: 1, modifiers: ['Shift', 'Alt', 'Ctrl']), "\x1b[<28;1;1M\x1b[<28;1;1m"];
        yield 'repeated modifier' => [Mouse::click(1, column: 1, modifiers: ['ctrl', 'Ctrl']), "\x1b[<16;1;1M\x1b[<16;1;1m"];
        yield 'hover' => [Mouse::move(4, column: 9), "\x1b[<35;9;4M"];
        yield 'wheel up, any case' => [Mouse::scroll(5, column: 6, direction: 'Up'), "\x1b[<64;6;5M"];
        yield 'drag, any case' => [Mouse::drag([[1, 1], [1, 2]], button: 'Right'), "\x1b[<2;1;1M\x1b[<34;2;1M\x1b[<2;2;1m"];
        yield 'wheel up' => [Mouse::scroll(5, column: 6, direction: 'up'), "\x1b[<64;6;5M"];
        yield 'wheel down twice' => [Mouse::scroll(5, column: 6, direction: 'down', notches: 2), "\x1b[<65;6;5M\x1b[<65;6;5M"];
        yield 'wheel right with shift' => [Mouse::scroll(1, column: 1, direction: 'right', notches: 1, modifiers: ['shift']), "\x1b[<71;1;1M"];
        yield 'drag' => [Mouse::drag([[1, 1], [1, 2], [2, 3]]), "\x1b[<0;1;1M\x1b[<32;2;1M\x1b[<32;3;2M\x1b[<0;3;2m"];
        yield 'right drag' => [Mouse::drag([[1, 1], [1, 2]], Mouse::RIGHT), "\x1b[<2;1;1M\x1b[<34;2;1M\x1b[<2;2;1m"];
    }

    #[DataProvider('reports')]
    public function testReports(string $bytes, string $expected): void
    {
        $this->assertSame($expected, $bytes);
    }

    /**
     * @return iterable<string, array{\Closure(): string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'button' => [static fn () => Mouse::click(1, column: 1, button: 'fourth'), 'Unknown mouse button "fourth"'];
        yield 'modifier' => [static fn () => Mouse::click(1, column: 1, modifiers: ['Hyper']), 'Unknown modifier "hyper"'];
        yield 'direction' => [static fn () => Mouse::scroll(1, column: 1, direction: 'sideways'), 'Unknown scroll direction "sideways"'];
        yield 'origin' => [static fn () => Mouse::click(0, column: 1), 'start at line 1, column 1'];
        yield 'short drag' => [static fn () => Mouse::drag([[1, 1]]), 'at least two cells'];
        yield 'no click' => [static fn () => Mouse::click(1, column: 1, count: 0), 'A click needs a count of 1 or more, not 0.'];
        yield 'no notch' => [static fn () => Mouse::scroll(1, column: 1, direction: 'up', notches: 0), 'A scroll needs a count of 1 or more, not 0.'];
        yield 'hover origin' => [static fn () => Mouse::move(0, column: 1), 'start at line 1, column 1'];
        yield 'scroll origin' => [static fn () => Mouse::scroll(1, column: 0, direction: 'up'), 'start at line 1, column 1'];
        yield 'drag origin' => [static fn () => Mouse::drag([[1, 1], [0, 1]]), 'start at line 1, column 1'];
        yield 'drag button' => [static fn () => Mouse::drag([[1, 1], [1, 2]], button: 'fourth'), 'Unknown mouse button "fourth"'];
    }

    /**
     * @param \Closure(): string $call
     */
    #[DataProvider('invalid')]
    public function testInvalid(\Closure $call, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $call();
    }

    public function testEachReportReachesTheApplicationAsOneInputEvent(): void
    {
        $session = $this->recorder();
        $session->click(2, column: 3, button: Mouse::LEFT, count: 2);

        $this->assertSame(["\x1b[<0;3;2M", "\x1b[<0;3;2m", "\x1b[<0;3;2M", "\x1b[<0;3;2m"], $this->received);
    }

    public function testClickingAnElementHitsItsMiddle(): void
    {
        $session = $this->recorder();
        // "ab\ncd\nef" is drawn on lines 2 to 4 of a 20-column screen: the middle is line 3, column 10
        $session->clickElement('#box');

        $this->assertSame(["\x1b[<0;10;3M", "\x1b[<0;10;3m"], $this->received);
    }

    public function testClickingText(): void
    {
        $session = $this->recorder();
        $session->clickText('界', Mouse::RIGHT);

        // Columns count display cells: 世 fills columns 1 and 2.
        $this->assertSame(["\x1b[<2;3;5M", "\x1b[<2;3;5m"], $this->received);
    }

    public function testDraggingBetweenElements(): void
    {
        $session = $this->recorder();
        $session->dragElement('#top', to: '#box');

        $this->assertSame(["\x1b[<0;10;1M", "\x1b[<32;10;2M", "\x1b[<32;10;3M", "\x1b[<0;10;3m"], $this->received);
    }

    public function testScrollingAnElement(): void
    {
        $session = $this->recorder();
        $session->scrollElement('#top', direction: 'down', notches: 3);

        $this->assertSame(\array_fill(0, count: 3, value: "\x1b[<65;10;1M"), $this->received);
    }

    public function testHoveringText(): void
    {
        $session = $this->recorder();
        $session->hoverText('cd');

        $this->assertSame(["\x1b[<35;1;3M"], $this->received);
    }

    public function testOffScreen(): void
    {
        $this->expectException(\OutOfRangeException::class);
        $this->expectExceptionMessageIsOrContains('Line 9, column 1 is off the 20x8 screen.');

        $this->recorder()->click(line: 9, column: 1);
    }

    public function testAnElementThatIsNotDrawn(): void
    {
        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessageIsOrContains('The element "#hidden" (text#hidden) is not on the screen, so it cannot be clicked.');

        $this->recorder()->clickElement('#hidden');
    }

    public function testTextThatIsNotShown(): void
    {
        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessageIsOrContains('The text "nowhere" was not found on the screen.');

        $this->recorder()->clickText('nowhere');
    }

    private function recorder(): Session
    {
        $this->session = new Session(
            new InProcessDriver(function (VirtualTerminal $terminal): Tui {
                $tui = new Tui(terminal: $terminal);
                $tui->add(new TextWidget('top')->setId('top'));
                $tui->add(new TextWidget("ab\ncd\nef")->setId('box'));
                $tui->add(new TextWidget('世界')->setId('wide'));
                $tui->add(new TextWidget('')->setId('hidden'));
                $tui->addListener(function (InputEvent $event): void {
                    $this->received[] = $event->getData();
                });

                return $tui;
            }),
            20,
            8,
            0.2,
            0.02,
        );
        $this->session->start();

        return $this->session;
    }
}
