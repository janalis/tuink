<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Driver\ProcessDriver;
use Janalis\Tuink\Driver\RunningProcess;
use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Exception\UnsupportedDriverException;
use Janalis\Tuink\Probe\ProbeServer;
use Janalis\Tuink\Selector\Element;
use Janalis\Tuink\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ProcessDriverTest extends TestCase
{
    private ?Session $session = null;

    protected function setUp(): void
    {
        if (!Process::isPtySupported()) {
            $this->markTestSkipped('This system has no pseudo-terminals.');
        }
    }

    protected function tearDown(): void
    {
        $this->session?->stop();
    }

    public function testTheRealEntryPoint(): void
    {
        $session = $this->demo(60, rows: 12);

        $this->assertSame('Tuink demo', $session->getLine(1));
        $session->assertSee('Size: 60x12');
        $session->send('Ada<Enter>');
        $session->press('Down');
        $session->press('Enter');
        $session->assertSee('You picked Green');
        $this->assertStringContainsString('Hello, Ada!', $session->getScreenText());
    }

    public function testResizeReachesTheApplication(): void
    {
        $session = $this->demo(60, rows: 12);
        $session->resize(44, rows: 9);

        $session->assertSee('Size: 44x9');
        $this->assertCount(9, $session->getLines());
    }

    public function testExitCode(): void
    {
        $session = $this->demo();
        $session->press('Enter');
        $session->press('Escape');

        $session->assertExited(2);
        $this->assertSame(2, $session->getDriver()->getExitCode());
    }

    public function testQuitting(): void
    {
        $session = $this->demo();
        $session->press('Ctrl+C');

        $session->assertExited(0);
        $this->assertFalse($session->getDriver()->isRunning());
    }

    public function testEnvironment(): void
    {
        $driver = new ProcessDriver(['/bin/sh', '-c', 'printf "%s %s %s" "$TERM" "$GREETING" "$(stty size)"; sleep 5'], null, ['GREETING' => 'hi']);
        $this->session = new Session($driver, 70, 20, 2.0);
        $this->session->start();

        $this->session->assertSee('xterm-256color hi 20 70');
        $this->assertTrue($driver->isRunning());
    }

    public function testSelectorsThroughTheProbe(): void
    {
        $session = $this->demo(60, rows: 12);
        $session->send('Ada<Enter>');

        $session->assertSeeIn('#greeting', text: 'Hello, Ada!');
        $this->assertSame(['select-list#color'], \array_map(static fn (Element $e): string => $e->describe(), $session->find(':focus')));
        $element = $session->findOne('#name');
        $this->assertSame('Ada', $element->value);
        // Widgets stay in the application
        $this->assertNull($element->widget);

        $session->focus('#name');
        $session->assertElementCount('input:focus', count: 1);
        $session->type('!');
        $this->assertSame('Ada!', $session->findOne('#name')->value);
    }

    public function testAProbeRequestWhileTheApplicationIsBlockedOnItsOutput(): void
    {
        $driver = new ProcessDriver([\PHP_BINARY, 'tests/Fixtures/apps/big-frames.php'], \dirname(__DIR__), probeTimeout: 5.0);
        $this->session = new Session($driver, 200, 100, 10.0);
        $this->session->start();

        // The key starts a timer that writes 200 kB at a time; with the driver not reading, the application blocks on
        // its output and only answers the request if the driver keeps draining it while it waits
        $driver->send('b');
        $driver->pump(0.1);
        \usleep(300_000);
        $tree = $driver->getWidgetTree();

        $this->assertStringContainsString('id="page"', $tree->toXml());
    }

    public function testAnApplicationWithoutTheProbe(): void
    {
        $driver = new ProcessDriver([\PHP_BINARY, 'example/bin/demo'], \dirname(__DIR__), ['TUINK_PROBE' => ''], probeTimeout: 0.3);
        $this->session = new Session($driver, 60, 12, 2.0);
        $this->session->start();

        foreach ([0.25, 0.2] as $i => $bound) {
            $start = \microtime(true);
            try {
                $this->session->find('input');
                $this->fail('An unsupported driver exception was expected.');
            } catch (UnsupportedDriverException $e) {
                $this->assertStringContainsString('did not connect to the tuink probe', $e->getMessage());
            }
            // The first lookup waits for the probe; once it is known missing, lookups fail at once
            $elapsed = \microtime(true) - $start;
            0 === $i ? $this->assertGreaterThan($bound, $elapsed) : $this->assertLessThan($bound, $elapsed);
        }
    }

    public function testTheProbeSocketIsRemoved(): void
    {
        $session = $this->demo();
        $session->find('input');
        // This driver's own socket: other test processes may be listening on theirs meanwhile
        $probe = self::probe($session);
        $this->assertFileExists($probe->path);

        $session->stop();

        $this->assertFileDoesNotExist($probe->path);
        $this->assertDirectoryDoesNotExist(\dirname($probe->path));
    }

    public function testTheBellAndTheTitle(): void
    {
        if ('Darwin' === \PHP_OS_FAMILY) {
            $this->markTestSkipped('On macOS, Symfony Tui plays a sound for the bell instead of writing BEL.');
        }

        $session = $this->demo();
        $session->assertTitle('Tuink demo');
        $session->press('Ctrl+G');
        $session->assertBell(1);
        $session->send('Ada<Enter>');
        $session->assertTitle('Tuink demo: Ada');
        $this->assertSame(1, $session->getDriver()->getObserver()->getBellCount());
        $this->assertSame('Tuink demo: Ada', $session->getDriver()->getObserver()->getTitle());
    }

    public function testTheTitle(): void
    {
        $session = $this->demo();
        $session->assertTitle('Tuink demo');
        $session->send('Ada<Enter>');
        $session->assertTitle('Tuink demo: Ada');
        $this->assertSame(0, $session->getDriver()->getObserver()->getBellCount());
    }

    public function testACommandThatDoesNotExist(): void
    {
        $this->session = new Session(new ProcessDriver(['/nonexistent/tuink-app']), timeout: 0.5);
        $this->session->start();

        $this->session->assertExited();
        // 127 where sh reports a missing command, 126 where it reports it cannot run it (macOS)
        $this->assertContains($this->session->getDriver()->getExitCode(), [126, 127]);
    }

    public function testStopEndsTheApplicationAndCleansUp(): void
    {
        $session = $this->demo();
        $running = self::runningProcess(new \ReflectionProperty(ProcessDriver::class, 'running')->getValue($session->getDriver()));
        $pid = $running->process->getPid();
        $ttyFile = self::string(new \ReflectionProperty(RunningProcess::class, 'ttyFile')->getValue($running));
        $this->assertIsInt($pid);
        $this->assertFileExists($ttyFile);

        $session->stop();

        $this->assertFalse($running->process->isRunning());
        $this->assertFileDoesNotExist($ttyFile);
        $this->assertFalse($session->getDriver()->isStarted());
    }

    public function testBeforeStart(): void
    {
        $driver = new ProcessDriver(['true']);
        $driver->stop();

        $this->assertFalse($driver->isStarted());
        $this->assertFalse($driver->isRunning());
        $this->assertNull($driver->getExitCode());
    }

    /**
     * @return iterable<string, array{\Closure(ProcessDriver): mixed}>
     */
    public static function beforeStart(): iterable
    {
        yield 'send' => [static fn (ProcessDriver $d) => $d->send('x')];
        yield 'resize' => [static fn (ProcessDriver $d) => $d->resize(columns: 10, rows: 5)];
        yield 'pump' => [static fn (ProcessDriver $d) => $d->pump(0.0)];
        yield 'screen' => [static fn (ProcessDriver $d) => $d->getScreen()];
        yield 'widget tree' => [static fn (ProcessDriver $d) => $d->getWidgetTree()];
    }

    /**
     * @param \Closure(ProcessDriver): mixed $call
     */
    #[DataProvider('beforeStart')]
    public function testNothingWorksBeforeStart(\Closure $call): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageIsOrContains('The application is not started.');

        $call(new ProcessDriver(['true']));
    }

    public function testAStartThatFails(): void
    {
        $temp = self::directory();
        $driver = new ProcessDriver(['true'], cwd: '/nonexistent/tuink', tempDir: $temp);

        try {
            $driver->start(columns: 20, rows: 5);
            $this->fail('A driver exception was expected.');
        } catch (DriverException $e) {
            $this->assertStringStartsWith('Cannot start "true": ', $e->getMessage());
        } finally {
            // The terminal name file and the probe directory are removed again
            $left = \array_diff((array) \scandir($temp), ['.', '..']);
            \rmdir($temp);
        }
        $this->assertSame([], $left);
        $this->assertFalse($driver->isStarted());
    }

    public function testATemporaryDirectoryOfItsOwn(): void
    {
        $temp = self::directory();
        $driver = new ProcessDriver([\PHP_BINARY, 'example/bin/demo'], \dirname(__DIR__), tempDir: $temp);
        $this->session = new Session($driver, 60, 12, 5.0);
        $this->session->start();

        try {
            $this->assertStringStartsWith($temp.'/tuink-', self::probe($this->session)->path);
            $this->assertSame(['input#name'], \array_map(static fn (Element $e): string => $e->describe(), $this->session->find('input')));
        } finally {
            $this->session->stop();
            \rmdir($temp);
        }
    }

    public function testResizingAnApplicationThatExited(): void
    {
        $session = $this->demo();
        $session->press('Ctrl+C');
        $session->assertExited();

        $this->expectException(DriverException::class);
        $this->expectExceptionMessageIsOrContains('Cannot resize: the application is not running.');
        $session->getDriver()->resize(columns: 30, rows: 10);
    }

    public function testPumpRunsForTheTimeItIsGivenAndSleepsMeanwhile(): void
    {
        $driver = new ProcessDriver(['/bin/sh', '-c', 'sleep 5']);
        $this->session = new Session($driver, 20, 5, 2.0);
        $driver->start(columns: 20, rows: 5);
        $driver->pump(0.1);
        $cpu = self::cpu();
        $start = \microtime(true);

        // A quiet application writes nothing
        $this->assertFalse($driver->pump(0.3));

        $this->assertGreaterThanOrEqual(0.3, \microtime(true) - $start);
        // It polls; a busy loop would spend the whole time on the CPU
        $this->assertLessThan(0.15, self::cpu() - $cpu);
    }

    public function testPumpReturnsOnceTheApplicationExited(): void
    {
        $session = $this->demo();
        $session->press('Ctrl+C');
        $session->assertExited();
        $start = \microtime(true);

        $session->getDriver()->pump(5.0);

        $this->assertLessThan(2.0, \microtime(true) - $start);
    }

    public function testPumpSaysWhetherTheApplicationWrote(): void
    {
        $driver = new ProcessDriver(['/bin/sh', '-c', 'printf ready; sleep 5']);
        $this->session = new Session($driver, 20, 5, 2.0);
        $driver->start(columns: 20, rows: 5);
        $wrote = false;
        for ($i = 0; $i < 100 && !$wrote; ++$i) {
            $wrote = $driver->pump(0.05);
        }

        $this->assertTrue($wrote);
        $this->assertSame('ready', $driver->getScreen()->getScreen());
    }

    public function testAnEscapeSequenceSplitAcrossWrites(): void
    {
        $driver = new ProcessDriver(['/bin/sh', '-c', 'printf "\\033[3"; sleep 0.3; printf "1mX"; sleep 5']);
        $this->session = new Session($driver, 20, 5, 2.0);
        $this->session->start();
        $this->session->assertSee('X');

        $this->assertSame('X', $this->session->getScreenText());
        $this->assertSame('red', $this->session->getTextStyles('X')[0]->foreground);
    }

    public function testAnEmptyCommand(): void
    {
        $this->expectException(DriverException::class);
        new ProcessDriver([]);
    }

    private function demo(int $columns = 80, int $rows = 24): Session
    {
        $driver = new ProcessDriver([\PHP_BINARY, 'example/bin/demo'], \dirname(__DIR__));
        $this->session = new Session($driver, $columns, $rows, 5.0);
        $this->session->start();

        return $this->session;
    }

    private static function probe(Session $session): ProbeServer
    {
        return self::runningProcess(new \ReflectionProperty(ProcessDriver::class, 'running')->getValue($session->getDriver()))->probe;
    }

    private static function runningProcess(mixed $running): RunningProcess
    {
        return $running instanceof RunningProcess ? $running : throw new \LogicException('The application is not running.');
    }

    /**
     * Seconds this process has spent on the CPU.
     */
    private static function cpu(): float
    {
        $usage = \getrusage();
        if (false === $usage) {
            throw new \RuntimeException('getrusage() failed.');
        }
        $seconds = 0.0;
        foreach (['ru_utime', 'ru_stime'] as $kind) {
            $seconds += (float) ($usage[$kind.'.tv_sec'] ?? 0) + ((float) ($usage[$kind.'.tv_usec'] ?? 0) / 1e6);
        }

        return $seconds;
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \LogicException('A string was expected.');
    }

    private static function directory(): string
    {
        $directory = \sys_get_temp_dir().'/tuink-test-'.\bin2hex(\random_bytes(4));
        \mkdir($directory);

        return $directory;
    }
}
