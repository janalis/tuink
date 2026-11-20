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
use Janalis\Tuink\Exception\DriverException;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\TextWidget;

final class InProcessDriverTest extends TestCase
{
    private ?InProcessDriver $driver = null;

    protected function tearDown(): void
    {
        $this->driver?->stop();
    }

    public function testStopStopsTheApplication(): void
    {
        $driver = $this->demo();
        $tui = $driver->getTui();
        $driver->stop();

        $this->assertFalse($tui->isRunning());
        $this->assertFalse($driver->isStarted());
    }

    public function testStopBeforeStartDoesNothing(): void
    {
        $driver = new InProcessDriver(static fn () => null);
        $driver->stop();

        $this->assertFalse($driver->isStarted());
    }

    /**
     * @return iterable<string, array{\Closure(InProcessDriver): mixed}>
     */
    public static function beforeStart(): iterable
    {
        yield 'send' => [static fn (InProcessDriver $d) => $d->send('x')];
        yield 'resize' => [static fn (InProcessDriver $d) => $d->resize(columns: 10, rows: 5)];
        yield 'pump' => [static fn (InProcessDriver $d) => $d->pump(0.0)];
        yield 'tui' => [static fn (InProcessDriver $d) => $d->getTui()];
        yield 'screen' => [static fn (InProcessDriver $d) => $d->getScreen()];
    }

    /**
     * @param \Closure(InProcessDriver): mixed $call
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('beforeStart')]
    public function testNothingWorksBeforeStart(\Closure $call): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageIsOrContains('The application is not started.');

        $call(new InProcessDriver(static fn () => null));
    }

    public function testFocusingAWidgetThatIsNotThere(): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageIsOrContains('No widget has the index 999.');

        $this->demo()->focusWidget(999);
    }

    public function testAResizeRepaintsTheWholeScreen(): void
    {
        $driver = $this->demo();
        $driver->getTui()->getTerminal()->write('STALE');
        $driver->resize(columns: 50, rows: 12);
        $driver->pump(0.05);

        $this->assertStringNotContainsString('STALE', $driver->getScreen()->getScreen());
        $this->assertStringContainsString('Size: 50x12', $driver->getScreen()->getScreen());
    }

    public function testTimersOtherCodeSetBeforeTheApplicationAreLeftAlone(): void
    {
        $foreignFired = false;
        $appFired = false;
        $foreign = EventLoop::delay(0.3, static function () use (&$foreignFired): void {
            $foreignFired = true;
        });
        $this->driver = new InProcessDriver(static function (VirtualTerminal $terminal) use (&$appFired): Tui {
            EventLoop::delay(0.3, static function () use (&$appFired): void {
                $appFired = true;
            });

            return new Tui(terminal: $terminal);
        });
        $this->driver->start(columns: 20, rows: 5);
        $this->driver->stop();

        $suspension = EventLoop::getSuspension();
        EventLoop::delay(0.4, $suspension->resume(...));
        $suspension->suspend();
        EventLoop::cancel($foreign);

        $this->assertTrue($foreignFired);
        $this->assertFalse($appFired);
    }

    public function testAnErrorInTheApplicationLeavesTheLoopUsable(): void
    {
        $this->driver = new InProcessDriver(static function (VirtualTerminal $terminal): Tui {
            $tui = new Tui(terminal: $terminal);
            $tui->add(new TextWidget('x'));
            EventLoop::defer(static function (): void {
                throw new \RuntimeException('boom');
            });

            return $tui;
        });
        $this->driver->start(columns: 20, rows: 5);
        $before = EventLoop::getIdentifiers();

        try {
            $this->driver->pump(0.3);
            $this->fail('The application\'s error was expected.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('boom', $e->getMessage().($e->getPrevious()?->getMessage() ?? ''));
        }

        // The pump's own timer is gone: nothing is left to fire later and resume a suspension nobody waits on
        $this->assertSame([], \array_values(\array_diff(EventLoop::getIdentifiers(), $before)));
    }

    private function demo(): InProcessDriver
    {
        $this->driver = new InProcessDriver(static fn (VirtualTerminal $terminal): Tui => new DemoApp($terminal)->tui);
        $this->driver->start(columns: 40, rows: 12);
        $this->driver->pump(0.05);

        return $this->driver;
    }
}
