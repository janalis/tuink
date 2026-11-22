<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Driver\InProcessApplicationInterface;
use Janalis\Tuink\Driver\InProcessDriver;
use Janalis\Tuink\Example\DemoApp;
use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Launch;
use Janalis\Tuink\Tests\Fixtures\RecordingApplication;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Process\Process;
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

    public function testAFactoryThatReturnsATuiHasNoApplication(): void
    {
        $this->assertNull($this->demo()->getApplication());
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

    public function testTheApplicationsSubprocessesGetTheEnvironment(): void
    {
        $_SERVER['TUINK_TEST_SERVER'] = ['not', 'a string'];
        $_ENV['TUINK_TEST_ENV'] = 'env before';
        unset($_SERVER['TUINK_TEST_NEW'], $_ENV['TUINK_TEST_NEW'], $_SERVER['TUINK_TEST_ENV'], $_ENV['TUINK_TEST_SERVER']);
        $seen = null;
        $driver = new InProcessDriver(static function (VirtualTerminal $terminal) use (&$seen): Tui {
            // Symfony Process builds a child's environment from $_SERVER and $_ENV, not from putenv() alone
            $process = new Process(['/bin/sh', '-c', 'printf %s "$TUINK_TEST_NEW,$TUINK_TEST_SERVER,$TUINK_TEST_ENV"']);
            $process->mustRun();
            $seen = $process->getOutput();

            return new Tui(terminal: $terminal);
        });
        $this->driver = $driver;

        try {
            $driver->start(20, rows: 5, launch: new Launch(env: ['TUINK_TEST_NEW' => 'new', 'TUINK_TEST_SERVER' => 'server', 'TUINK_TEST_ENV' => 'env']));
            $this->assertSame('new,server,env', $seen);
            $this->assertSame(['new', 'new'], [$_SERVER['TUINK_TEST_NEW'], $_ENV['TUINK_TEST_NEW']]);
            $driver->stop();

            $this->assertArrayNotHasKey('TUINK_TEST_NEW', $_SERVER);
            $this->assertArrayNotHasKey('TUINK_TEST_NEW', $_ENV);
            $this->assertSame(['not', 'a string'], $_SERVER['TUINK_TEST_SERVER']);
            $this->assertArrayNotHasKey('TUINK_TEST_SERVER', $_ENV);
            $this->assertSame('env before', $_ENV['TUINK_TEST_ENV']);
            $this->assertArrayNotHasKey('TUINK_TEST_ENV', $_SERVER);
            $this->assertFalse(\getenv('TUINK_TEST_NEW'));
        } finally {
            unset($_SERVER['TUINK_TEST_SERVER'], $_ENV['TUINK_TEST_ENV']);
        }
    }

    /**
     * @return iterable<string, array{\Closure(VirtualTerminal): mixed, class-string<\Throwable>, string}>
     */
    public static function failingFactories(): iterable
    {
        yield 'throws' => [static fn () => throw new \RuntimeException('boom'), \RuntimeException::class, 'boom'];
        yield 'returns something else' => [
            static fn () => null,
            DriverException::class,
            'The in-process factory must return a "Symfony\Component\Tui\Tui" or an "Janalis\Tuink\Driver\InProcessApplicationInterface", "null" returned.',
        ];
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

    public function testAVirtualTerminalHasNoLineMode(): void
    {
        $this->assertFalse($this->demo()->isInLineMode());
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
        yield 'application' => [static fn (InProcessDriver $d) => $d->getApplication()];
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
        // The repaint comes on the Tui's next tick, which a loaded machine delays
        $deadline = \microtime(true) + 5.0;
        do {
            $driver->pump(0.05);
        } while (!\str_contains($driver->getScreen()->getScreen(), 'Size: 50x12') && \microtime(true) < $deadline);

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

    public function testAnApplicationBootsBeforeTheTuiStartsAndShutsDownAfterItStops(): void
    {
        /** @var list<string> $calls */
        $calls = [];
        $driver = new InProcessDriver(static function (VirtualTerminal $terminal) use (&$calls): InProcessApplicationInterface {
            return new RecordingApplication(new Tui(terminal: $terminal), $calls);
        });
        $this->driver = $driver;

        $driver->start(20, rows: 5, launch: new Launch(env: ['TUINK_TEST_APP' => 'set']));
        $application = $driver->getApplication();
        $driver->stop();

        $this->assertInstanceOf(RecordingApplication::class, $application);
        $this->assertSame(['boot: not running, set', 'shutdown: not running, set'], $calls);
        $this->assertFalse(\getenv('TUINK_TEST_APP'));
        $this->assertFalse($driver->isStarted());
    }

    public function testTimersTheShutdownLeavesAreCancelled(): void
    {
        $before = EventLoop::getIdentifiers();
        $calls = [];
        $driver = new InProcessDriver(static function (VirtualTerminal $terminal) use (&$calls): InProcessApplicationInterface {
            return new RecordingApplication(
                new Tui(terminal: $terminal),
                $calls,
                onShutdown: static function (): void {
                    EventLoop::delay(10, static fn () => null);
                },
            );
        });
        $this->driver = $driver;

        $driver->start(20, rows: 5);
        $driver->stop();

        $this->assertSame([], \array_values(\array_diff(EventLoop::getIdentifiers(), $before)));
    }

    public function testAShutdownThatThrowsStillReleasesTheRun(): void
    {
        $calls = [];
        $driver = new InProcessDriver(static function (VirtualTerminal $terminal) use (&$calls): InProcessApplicationInterface {
            return new RecordingApplication(
                new Tui(terminal: $terminal),
                $calls,
                onShutdown: static fn () => throw new \RuntimeException('teardown'),
            );
        });
        $this->driver = $driver;
        $driver->start(20, rows: 5, launch: new Launch(env: ['TUINK_TEST_APP' => 'set']));

        try {
            $driver->stop();
            $this->fail('The stop was expected to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('teardown', $e->getMessage());
        }

        $this->assertFalse($driver->isStarted());
        $this->assertFalse(\getenv('TUINK_TEST_APP'));
        // Released once: a second stop has nothing left to shut down
        $driver->stop();
        $this->assertSame(['boot: not running, set', 'shutdown: not running, set'], $calls);
    }

    public function testABootThatThrowsIsNotShutDownAndReleasesTheRun(): void
    {
        $before = EventLoop::getIdentifiers();
        $calls = [];
        $driver = new InProcessDriver(static function (VirtualTerminal $terminal) use (&$calls): InProcessApplicationInterface {
            return new RecordingApplication(
                new Tui(terminal: $terminal),
                $calls,
                onBoot: static function (): void {
                    EventLoop::delay(10, static fn () => null);

                    throw new \RuntimeException('no boot');
                },
            );
        });
        $this->driver = $driver;

        try {
            $driver->start(20, rows: 5, launch: new Launch(env: ['TUINK_TEST_APP' => 'set']));
            $this->fail('The start was expected to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('no boot', $e->getMessage());
        }

        $this->assertFalse($driver->isStarted());
        $this->assertFalse(\getenv('TUINK_TEST_APP'));
        $this->assertSame([], \array_values(\array_diff(EventLoop::getIdentifiers(), $before)));
        $driver->stop();
        $this->assertSame(['boot: not running, set'], $calls);
    }

    public function testAStartThatThrowsAfterTheBootStillShutsTheApplicationDown(): void
    {
        $before = EventLoop::getIdentifiers();
        $calls = [];
        $driver = new InProcessDriver(static function (VirtualTerminal $terminal) use (&$calls): InProcessApplicationInterface {
            return new RecordingApplication(
                new class(terminal: $terminal) extends Tui {
                    #[\Override]
                    public function start(): void
                    {
                        throw new \RuntimeException('no start');
                    }
                },
                $calls,
                onBoot: static function (): void {
                    EventLoop::delay(10, static fn () => null);
                },
            );
        });
        $this->driver = $driver;

        try {
            $driver->start(20, rows: 5, launch: new Launch(env: ['TUINK_TEST_APP' => 'set']));
            $this->fail('The start was expected to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('no start', $e->getMessage());
        }

        $this->assertSame(['boot: not running, set', 'shutdown: not running, set'], $calls);
        $this->assertFalse($driver->isStarted());
        $this->assertFalse(\getenv('TUINK_TEST_APP'));
        $this->assertSame([], \array_values(\array_diff(EventLoop::getIdentifiers(), $before)));
    }

    public function testAStartThatThrowsHalfWayStopsTheTuiBeforeTheShutdown(): void
    {
        /** @var list<string> $calls */
        $calls = [];
        $driver = new InProcessDriver(static function (VirtualTerminal $terminal) use (&$calls): InProcessApplicationInterface {
            return new RecordingApplication(
                new class(terminal: $terminal) extends Tui {
                    #[\Override]
                    public function start(): void
                    {
                        parent::start();

                        throw new \RuntimeException('no first frame');
                    }
                },
                $calls,
            );
        });
        $this->driver = $driver;

        try {
            $driver->start(20, rows: 5);
            $this->fail('The start was expected to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('no first frame', $e->getMessage());
        }

        $this->assertSame(['boot: not running, ', 'shutdown: not running, '], $calls);
        $this->assertFalse($driver->isStarted());
    }

    public function testAnApplicationWhoseTuiQuitsIsShutDownAtOnceAndOnlyOnce(): void
    {
        /** @var list<string> $calls */
        $calls = [];
        $driver = new InProcessDriver(static function (VirtualTerminal $terminal) use (&$calls): InProcessApplicationInterface {
            return new RecordingApplication(new Tui(terminal: $terminal), $calls);
        });
        $this->driver = $driver;
        $driver->start(20, rows: 5);
        $driver->pump(0.0);
        $running = $calls;

        // As a quit key does
        $driver->getApplication()?->getTui()->stop();
        $driver->pump(0.0);
        $quit = $calls;
        // The steps after the quit still reach it
        $this->assertInstanceOf(RecordingApplication::class, $driver->getApplication());
        $driver->pump(0.0);
        $driver->stop();

        $this->assertSame(['boot: not running, '], $running);
        $this->assertSame(['boot: not running, ', 'shutdown: not running, '], $quit);
        $this->assertSame(['boot: not running, ', 'shutdown: not running, '], $calls);
    }

    public function testAStopThatThrowsStillShutsTheApplicationDown(): void
    {
        $calls = [];
        $driver = new InProcessDriver(static function (VirtualTerminal $terminal) use (&$calls): InProcessApplicationInterface {
            return new RecordingApplication(
                new class(terminal: $terminal) extends Tui {
                    #[\Override]
                    public function stop(): void
                    {
                        parent::stop();

                        throw new \RuntimeException('no stop');
                    }
                },
                $calls,
            );
        });
        $this->driver = $driver;
        $driver->start(20, rows: 5);

        try {
            $driver->stop();
            $this->fail('The stop was expected to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('no stop', $e->getMessage());
        }

        $this->assertSame(['boot: not running, ', 'shutdown: not running, '], $calls);
        $this->assertFalse($driver->isStarted());
    }

    private function demo(): InProcessDriver
    {
        $this->driver = new InProcessDriver(static fn (VirtualTerminal $terminal): Tui => new DemoApp($terminal)->tui);
        $this->driver->start(columns: 40, rows: 12);
        $this->driver->pump(0.05);

        return $this->driver;
    }
}
