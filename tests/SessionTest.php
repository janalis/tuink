<?php

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
use Janalis\Tuink\Exception\ExpectationException;
use Janalis\Tuink\Exception\UnsupportedDriverException;
use Janalis\Tuink\Session;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\TextWidget;

final class SessionTest extends TestCase
{
    private ?Session $session = null;
    private ?string $snapshotDir = null;

    protected function tearDown(): void
    {
        $this->session?->stop();
        if (null !== $this->snapshotDir && is_dir($this->snapshotDir)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->snapshotDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->snapshotDir);
        }
        putenv('CI');
        putenv('TUINK_UPDATE_SNAPSHOTS');
    }

    public function testStartSettlesOnTheFirstFrame(): void
    {
        $session = $this->demo();

        $this->assertSame('Tuink demo', $session->getLine(1));
        $this->assertSame('Name:', $session->getLine(4));
        $this->assertCount(24, $session->getLines());
    }

    public function testTypingAndPressing(): void
    {
        $session = $this->demo();
        $session->type('Ada');
        $session->press('Enter');
        $session->press('Down', 2);
        $session->press('Enter');

        $session->assertSee('You picked Blue');
        $this->assertStringContainsString('Hello, Ada!', $session->getScreenText());
    }

    public function testAssertSeeWaitsForLateFrames(): void
    {
        $session = $this->demo();
        $this->assertStringContainsString('Status: loading', $session->getScreenText());

        $session->assertSee('Status: ready');
    }

    public function testAssertSeeFailsWithTheNumberedScreen(): void
    {
        $session = $this->demo(timeout: 0.1);

        try {
            $session->assertSee('Goodbye');
            $this->fail('An expectation exception was expected.');
        } catch (ExpectationException $e) {
            $this->assertStringStartsWith('The text "Goodbye" was not found on the screen.', $e->getMessage());
            $this->assertStringContainsString("Screen (80x24, in_process driver, running):\n1 | Tuink demo\n", $e->getMessage());
        }
    }

    public function testAssertNotSee(): void
    {
        $session = $this->demo(timeout: 0.1);
        $session->assertNotSee('Hello');

        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessage('The text "Tuink" was found on the screen.');
        $session->assertNotSee('Tuink');
    }

    public function testAssertLines(): void
    {
        $session = $this->demo(timeout: 0.1);
        $session->type('Ada');
        $session->assertLineEquals(4, 'Name: Ada  ');
        $session->assertLineContains(4, 'Ada');

        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessage('Line 30 does not contain "Ada".');
        $session->assertLineContains(30, 'Ada');
    }

    public function testGetLineOutOfRange(): void
    {
        $this->expectException(\OutOfRangeException::class);
        $this->demo()->getLine(25);
    }

    public function testAssertScreenEqualsIgnoresTrailingSpaceAndBlankLines(): void
    {
        $session = $this->session = new Session(new InProcessDriver(static function (VirtualTerminal $terminal): Tui {
            $tui = new Tui(terminal: $terminal);
            $tui->add(new TextWidget("one\n  two"));

            return $tui;
        }), 20, 5, 0.1);
        $session->start();

        $session->assertScreenEquals("one   \n  two\n\n");

        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessage("The screen is not:\n1 | one\n2 | two");
        $session->assertScreenEquals("one\ntwo");
    }

    public function testSnapshotIsWrittenThenCompared(): void
    {
        $session = $this->demo(snapshots: true, timeout: 0.1);
        $path = $this->snapshotDir.'/demo/start.txt';

        $session->assertMatchesSnapshot('demo/start');
        $this->assertFileExists($path);
        $this->assertStringStartsWith("Tuink demo\n", (string) file_get_contents($path));
        $session->assertMatchesSnapshot('demo/start');

        $session->type('Ada');
        $this->expectException(ExpectationException::class);
        $session->assertMatchesSnapshot('demo/start');
    }

    public function testSnapshotUpdate(): void
    {
        $session = $this->demo(snapshots: true, timeout: 0.1);
        $session->assertMatchesSnapshot('start');
        $session->type('Ada');

        putenv('TUINK_UPDATE_SNAPSHOTS=1');
        $session->assertMatchesSnapshot('start');

        $this->assertStringContainsString('Name: Ada', (string) file_get_contents($this->snapshotDir.'/start.txt'));
    }

    public function testMissingSnapshotFailsOnCi(): void
    {
        putenv('CI=true');
        $session = $this->demo(snapshots: true, timeout: 0.1);

        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessage('does not exist; record it locally and commit it.');
        $session->assertMatchesSnapshot('start');
    }

    public function testSnapshotNamesStayInTheDirectory(): void
    {
        $session = $this->demo(snapshots: true, timeout: 0.1);

        $this->expectException(\InvalidArgumentException::class);
        $session->assertMatchesSnapshot('../escape');
    }

    public function testExit(): void
    {
        $session = $this->demo(timeout: 0.5);
        $session->press('Ctrl+C');
        $session->assertExited();

        $this->assertStringContainsString('in_process driver, exited', $session->dump());

        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessage('The application is no longer running.');
        $session->type('x');
    }

    public function testInProcessExitCodesAreUnsupported(): void
    {
        $session = $this->demo(timeout: 0.5);
        $session->press('Ctrl+C');

        $this->expectException(UnsupportedDriverException::class);
        $session->assertExited(0);
    }

    public function testActionsNeedAStartedApplication(): void
    {
        $this->session = new Session(InProcessDriver::fromFile(\dirname(__DIR__).'/example/factory.php'));

        $this->assertSame('The application is not started.', $this->session->dump());
        $this->expectException(DriverException::class);
        $this->session->type('x');
    }

    public function testTheApplicationsTimersDieWithIt(): void
    {
        $fired = false;
        $session = $this->session = new Session(new InProcessDriver(static function (VirtualTerminal $terminal) use (&$fired): Tui {
            EventLoop::delay(0.3, static function () use (&$fired): void {
                $fired = true;
            });

            return new Tui(terminal: $terminal);
        }), timeout: 0.1);
        $session->start();
        $session->stop();

        $suspension = EventLoop::getSuspension();
        EventLoop::delay(0.4, static fn () => $suspension->resume());
        $suspension->suspend();

        $this->assertFalse($fired);
    }

    public function testResize(): void
    {
        $session = $this->demo(timeout: 0.5);
        $session->resize(40, 8);

        $this->assertCount(8, $session->getLines());
        $session->assertSee('Size: 40x8');
        $session->assertSee('Tuink demo');
    }

    public function testPaste(): void
    {
        $session = $this->demo(timeout: 0.5);
        $session->paste('Grace');
        $session->send('<Enter>');

        $session->assertSee('Hello, Grace!');
        $this->assertSame('Name: Grace', $session->getLine(4));
    }

    private function demo(float $timeout = 2.0, bool $snapshots = false): Session
    {
        if ($snapshots) {
            $this->snapshotDir = sys_get_temp_dir().'/tuink-snapshots-'.bin2hex(random_bytes(4));
        }

        $this->session = new Session(
            new InProcessDriver(static fn (VirtualTerminal $terminal): Tui => (new DemoApp($terminal))->tui),
            timeout: $timeout,
            quietPeriod: 0.02,
            snapshotDir: $this->snapshotDir,
        );
        $this->session->start();

        return $this->session;
    }
}
