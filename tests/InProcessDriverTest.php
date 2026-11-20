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
use Janalis\Tuink\Launch;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testTheFactoryGetsTheResolvedLaunchAndTheEnvironmentIsSetForTheRun(): void
    {
        \putenv('TUINK_TEST_SET=before');
        \putenv('TUINK_TEST_UNSET');
        /** @var list<array{Launch, list<string|bool>}> $runs What each start's factory saw */
        $runs = [];
        $driver = new InProcessDriver(static function (VirtualTerminal $terminal, Launch $launch) use (&$runs): Tui {
            $runs[] = [$launch, [\getenv('TUINK_TEST_SET'), \getenv('TUINK_TEST_UNSET'), \getenv('TUINK_WORKSPACE'), $terminal->isKittyProtocolActive()]];

            return new Tui(terminal: $terminal);
        });
        $this->driver = $driver;

        try {
            $driver->start(20, rows: 5, launch: new Launch(['%workspace%/a'], ['TUINK_TEST_SET' => 'during', 'TUINK_TEST_UNSET' => '%workspace%'], '/w', kitty: true));
            $this->assertSame('during', \getenv('TUINK_TEST_SET'));
            $driver->stop();
            $after = [\getenv('TUINK_TEST_SET'), \getenv('TUINK_TEST_UNSET'), \getenv('TUINK_WORKSPACE')];
            // A second run sets and restores again
            $driver->start(20, rows: 5, launch: new Launch(env: ['TUINK_TEST_SET' => 'again']));
            $driver->stop();

            $this->assertSame(['before', false, false], $after);
            $this->assertSame('before', \getenv('TUINK_TEST_SET'));
            $this->assertEquals(
                [
                    [new Launch(['/w/a'], ['TUINK_WORKSPACE' => '/w', 'TUINK_TEST_SET' => 'during', 'TUINK_TEST_UNSET' => '/w'], '/w', kitty: true), ['during', '/w', '/w', true]],
                    [new Launch(env: ['TUINK_TEST_SET' => 'again']), ['again', false, false, false]],
                ],
                $runs,
            );
        } finally {
            \putenv('TUINK_TEST_SET');
        }
    }

    /**
     * @return iterable<string, array{\Closure(VirtualTerminal): mixed, class-string<\Throwable>, string}>
     */
    public static function failingFactories(): iterable
    {
        yield 'throws' => [static fn () => throw new \RuntimeException('boom'), \RuntimeException::class, 'boom'];
        yield 'returns something else' => [static fn () => null, DriverException::class, 'The in-process factory must return a "Symfony\Component\Tui\Tui", "null" returned.'];
        yield 'uses another terminal' => [
            static fn () => new Tui(terminal: new VirtualTerminal()),
            DriverException::class,
            'The in-process factory must build the Tui on the VirtualTerminal it receives.',
        ];
    }

    /**
     * @param \Closure(VirtualTerminal): mixed $factory
     * @param class-string<\Throwable>       $class
     */
    #[DataProvider('failingFactories')]
    public function testAStartThatFailsRestoresTheEnvironment(\Closure $factory, string $class, string $message): void
    {
        $driver = new InProcessDriver($factory);
        $this->driver = $driver;

        try {
            $driver->start(20, rows: 5, launch: new Launch(env: ['TUINK_TEST_FAIL' => 'x']));
            $this->fail('The start was expected to fail.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertSame($message, $e->getMessage());
            $this->assertFalse(\getenv('TUINK_TEST_FAIL'));
            $this->assertFalse($driver->isStarted());
        }
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
