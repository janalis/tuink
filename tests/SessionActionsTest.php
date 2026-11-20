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
use Janalis\Tuink\Example\DemoApp;
use Janalis\Tuink\Exception\ExpectationException;
use Janalis\Tuink\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Event\FocusEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * What Session actions send, and the state they leave.
 */
final class SessionActionsTest extends TestCase
{
    /** @var list<string> */
    private array $received = [];
    private ?Session $session = null;

    protected function tearDown(): void
    {
        $this->session?->close();
    }

    public function testDefaultsSendOneClickOrNotch(): void
    {
        $session = $this->recorder();
        $session->click(line: 1, column: 1);
        $session->clickElement('#top');
        $session->clickText('cd');
        $session->scroll(line: 1, column: 1, direction: 'up');
        $session->scrollElement('#top', direction: 'down');

        $this->assertSame(
            [
                "\x1b[<0;1;1M",
                "\x1b[<0;1;1m",
                "\x1b[<0;11;1M",
                "\x1b[<0;11;1m",
                "\x1b[<0;1;3M",
                "\x1b[<0;1;3m",
                "\x1b[<64;1;1M",
                "\x1b[<65;11;1M",
            ],
            $this->received,
        );
    }

    public function testTheMiddleOfAnElementWithAnEvenHeightAndAnOddWidth(): void
    {
        // #box is drawn on lines 2 and 3 of a 21-column screen: the middle cell is line 2, column 11
        $this->recorder()->clickElement('#box');

        $this->assertSame(["\x1b[<0;11;2M", "\x1b[<0;11;2m"], $this->received);
    }

    public function testClickingTextAfterAWideCharacter(): void
    {
        // On "世xy", 世 fills columns 1 and 2, so y is in column 4
        $this->recorder()->clickText('y');

        $this->assertSame(["\x1b[<0;4;4M", "\x1b[<0;4;4m"], $this->received);
    }

    /**
     * @return iterable<string, array{array{int, int, int, int}, list<string>}>
     */
    public static function drags(): iterable
    {
        yield 'diagonal, rounded per step' => [
            [1, 1, 3, 6],
            ["\x1b[<0;1;1M", "\x1b[<32;2;1M", "\x1b[<32;3;2M", "\x1b[<32;4;2M", "\x1b[<32;5;3M", "\x1b[<32;6;3M", "\x1b[<0;6;3m"],
        ];
        yield 'leftwards' => [
            [2,              5,               2,               3],
            ["\x1b[<0;5;2M", "\x1b[<32;4;2M", "\x1b[<32;3;2M", "\x1b[<0;3;2m"],
        ];
        yield 'a quarter cell per step' => [[1, 1, 5, 2], ["\x1b[<0;1;1M", "\x1b[<32;1;2M", "\x1b[<32;2;3M", "\x1b[<32;2;4M", "\x1b[<32;2;5M", "\x1b[<0;2;5m"]];
        yield 'in place' => [[2, 2, 2, 2], ["\x1b[<0;2;2M", "\x1b[<32;2;2M", "\x1b[<0;2;2m"]];
    }

    /**
     * @param array{int, int, int, int} $path
     * @param list<string>              $reports
     */
    #[DataProvider('drags')]
    public function testDragPaths(array $path, array $reports): void
    {
        $this->recorder()->drag(...$path);

        $this->assertSame($reports, $this->received);
    }

    public function testHover(): void
    {
        $this->recorder()->hover(line: 2, column: 3);

        $this->assertSame(["\x1b[<35;3;2M"], $this->received);
    }

    public function testFocusEventsSettle(): void
    {
        $session = $this->demo();
        $session->press('FocusOut');

        // Read at once: the action waited for the frame
        $this->assertStringContainsString('Window unfocused', $session->getScreenText());
        $session->press('FocusIn');
        $this->assertStringContainsString('Window focused', $session->getScreenText());
    }

    public function testPasteWrapsTheTextInBracketedPasteMarkers(): void
    {
        $this->recorder()->paste('x y');

        $this->assertSame(["\x1b[200~x y\x1b[201~"], $this->received);
    }

    /**
     * @return iterable<string, array{\Closure(Session): void}>
     */
    public static function outOfScreen(): iterable
    {
        yield 'click above' => [static fn (Session $s) => $s->click(line: 0, column: 1)];
        yield 'click left' => [static fn (Session $s) => $s->click(line: 1, column: 0)];
        yield 'click below' => [static fn (Session $s) => $s->click(line: 9, column: 1)];
        yield 'click right' => [static fn (Session $s) => $s->click(line: 1, column: 22)];
        yield 'scroll' => [static fn (Session $s) => $s->scroll(line: 9, column: 1, direction: 'up')];
        yield 'drag from' => [static fn (Session $s) => $s->drag(fromLine: 9, fromColumn: 1, toLine: 1, toColumn: 1)];
        yield 'drag to' => [static fn (Session $s) => $s->drag(fromLine: 1, fromColumn: 1, toLine: 1, toColumn: 22)];
        yield 'hover' => [static fn (Session $s) => $s->hover(line: 1, column: 22)];
    }

    /**
     * @param \Closure(Session): void $action
     */
    #[DataProvider('outOfScreen')]
    public function testPositionsMustBeOnTheScreen(\Closure $action): void
    {
        $session = $this->recorder();

        try {
            $action($session);
            $this->fail('An out of range exception was expected.');
        } catch (\OutOfRangeException $e) {
            $this->assertStringContainsString('is off the 21x8 screen.', $e->getMessage());
        }
        $this->assertSame([], $this->received);
    }

    public function testTheLastLineAndColumnAreOnTheScreen(): void
    {
        $this->recorder()->click(line: 8, column: 21);

        $this->assertSame(["\x1b[<0;21;8M", "\x1b[<0;21;8m"], $this->received);
    }

    /**
     * @return iterable<string, array{\Closure(Session): void}>
     */
    public static function actions(): iterable
    {
        yield 'type' => [static fn (Session $s) => $s->type('x')];
        yield 'press' => [static fn (Session $s) => $s->press('Enter')];
        yield 'send' => [static fn (Session $s) => $s->send('x')];
        yield 'paste' => [static fn (Session $s) => $s->paste('x')];
        yield 'click' => [static fn (Session $s) => $s->click(line: 1, column: 1)];
        yield 'scroll' => [static fn (Session $s) => $s->scroll(line: 1, column: 1, direction: 'up')];
        yield 'drag' => [static fn (Session $s) => $s->drag(fromLine: 1, fromColumn: 1, toLine: 2, toColumn: 2)];
        yield 'hover' => [static fn (Session $s) => $s->hover(line: 1, column: 1)];
        yield 'resize' => [static fn (Session $s) => $s->resize(columns: 30, rows: 10)];
        yield 'focus' => [static fn (Session $s) => $s->focus('#name')];
    }

    /**
     * @param \Closure(Session): void $action
     */
    #[DataProvider('actions')]
    public function testActionsNeedARunningApplication(\Closure $action): void
    {
        $session = $this->demo();
        $session->press('Ctrl+C');

        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessageIsOrContains('The application is no longer running.');
        $action($session);
    }

    /**
     * Each action settles: the screen shows its effect when it returns, without a waiting check.
     *
     * @return iterable<string, array{\Closure(Session): void, string}>
     */
    public static function settled(): iterable
    {
        yield 'type' => [static fn (Session $s) => $s->type('Ada'), 'Name: Ada'];
        yield 'press' => [static fn (Session $s) => $s->press('Enter'), 'Hello, !'];
        yield 'resize' => [static fn (Session $s) => $s->resize(columns: 30, rows: 10), 'Size: 30x10'];
        yield 'paste' => [static fn (Session $s) => $s->paste('Ada'), 'Name: Ada'];
        yield 'click' => [static fn (Session $s) => $s->click(line: 7, column: 3), 'You picked Blue'];
        yield 'scroll' => [static fn (Session $s) => $s->scroll(line: 5, column: 3, direction: 'down'), '→ Green'];
        yield 'drag' => [static fn (Session $s) => $s->drag(fromLine: 1, fromColumn: 1, toLine: 2, toColumn: 4), 'Dragged to 2:4'];
        yield 'hover' => [static fn (Session $s) => $s->hover(line: 2, column: 3), 'Hover at 2:3'];
    }

    /**
     * @param \Closure(Session): void $action
     */
    #[DataProvider('settled')]
    public function testActionsSettle(\Closure $action, string $shown): void
    {
        $session = $this->demo();
        $action($session);

        $this->assertStringContainsString($shown, $session->getScreenText());
    }

    public function testSelectingFromSomethingThatIsNotAList(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The element "#name" has no selected item; it is not a list.');

        $this->demo()->selectOption('#name', label: 'Red');
    }

    public function testFindOneWaitsForAWidgetAddedLater(): void
    {
        $this->session = new Session(
            new InProcessDriver(static function (VirtualTerminal $terminal): Tui {
                $tui = new Tui(terminal: $terminal);
                $tui->add(new TextWidget('first'));
                EventLoop::delay(0.2, static function () use ($tui): void {
                    $tui->add(new TextWidget('late')->setId('late'));
                    $tui->requestRender();
                });

                return $tui;
            }),
            timeout: 2.0,
            quietPeriod: 0.02,
        );
        $this->session->start();

        $this->assertSame('late', $this->session->findOne('#late')->id);
    }

    public function testAssertExitedWaitsForTheApplicationToQuit(): void
    {
        $this->session = new Session(
            new InProcessDriver(static function (VirtualTerminal $terminal): Tui {
                $tui = new Tui(terminal: $terminal);
                $tui->add(new TextWidget('bye soon'));
                EventLoop::delay(0.2, $tui->stop(...));

                return $tui;
            }),
            timeout: 2.0,
            quietPeriod: 0.02,
        );
        $this->session->start();
        $this->session->assertExited();

        $this->assertFalse($this->session->getDriver()->isRunning());
    }

    public function testTheScreenStaysReadableAfterTheApplicationStopped(): void
    {
        $session = $this->demo();
        $session->stop();

        $this->assertStringStartsWith("Screen (80x24, in_process driver, exited):\n1 | Tuink demo", $session->dump());
    }

    private function demo(): Session
    {
        $this->session = new Session(
            new InProcessDriver(static fn (VirtualTerminal $terminal): Tui => new DemoApp($terminal)->tui),
            timeout: 1.0,
            quietPeriod: 0.02,
        );
        $this->session->start();

        return $this->session;
    }

    public function testFocusSettles(): void
    {
        $this->session = new Session(
            new InProcessDriver(static function (VirtualTerminal $terminal): Tui {
                $tui = new Tui(terminal: $terminal);
                $status = new TextWidget('no focus')->setId('status');
                $tui->add($status);
                $first = new InputWidget()->setId('first');
                $tui->add($first);
                $tui->add(new InputWidget()->setId('field'));
                $tui->setFocus($first);
                $tui->getFocusManager()->onFocusChanged(static function (FocusEvent $event) use ($tui, $status): void {
                    $status->setText('focus on '.(string) $event->getTarget()->getId());
                    $tui->requestRender();
                });

                return $tui;
            }),
            timeout: 1.0,
            quietPeriod: 0.02,
        );
        $this->session->start();
        $this->session->focus('#field');

        $this->assertSame('focus on field', $this->session->getLine(1));
    }

    public function testStartWaitsForAFrameBehindEarlierEscapeSequences(): void
    {
        $this->session = new Session(
            new InProcessDriver(static function (VirtualTerminal $terminal): Tui {
                $tui = new Tui(terminal: $terminal);
                $tui->getTerminal()->setTitle('booting');
                $text = new TextWidget('');
                $tui->add($text);
                EventLoop::delay(0.2, static function () use ($tui, $text): void {
                    $text->setText('ready');
                    $tui->requestRender();
                });

                return $tui;
            }),
            timeout: 2.0,
            quietPeriod: 0.02,
        );
        $this->session->start();

        $this->assertSame('ready', $this->session->getScreenText());
    }

    public function testStartTakesTheSizeItIsGiven(): void
    {
        $this->session = new Session(new InProcessDriver(static fn (VirtualTerminal $terminal): Tui => new DemoApp($terminal)->tui), timeout: 1.0, quietPeriod: 0.02);
        $this->session->start(columns: 40, rows: 10);

        $this->assertSame(40, $this->session->getColumns());
        $this->assertSame(10, $this->session->getRows());
        $this->assertStringContainsString('Size: 40x10', $this->session->getScreenText());
    }

    public function testSettleLetsTheApplicationCatchUp(): void
    {
        $session = $this->demo();
        // Straight to the driver, which does not wait
        $session->getDriver()->send('Ada');
        $session->settle();

        $this->assertSame('Name: Ada', $session->getLine(4));
    }

    private function recorder(): Session
    {
        $this->session = new Session(
            new InProcessDriver(function (VirtualTerminal $terminal): Tui {
                $tui = new Tui(terminal: $terminal);
                $tui->add(new TextWidget('top')->setId('top'));
                $tui->add(new TextWidget("ab\ncd")->setId('box'));
                $tui->add(new TextWidget('世xy')->setId('wide'));
                $tui->addListener(function (InputEvent $event): void {
                    $this->received[] = $event->getData();
                });

                return $tui;
            }),
            21,
            8,
            0.2,
            0.02,
        );
        $this->session->start();

        return $this->session;
    }
}
