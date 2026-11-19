<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Driver\ProcessDriver;
use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Session;
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
        $session = $this->demo(60, 12);

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
        $session = $this->demo(60, 12);
        $session->resize(44, 9);

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

    public function testACommandThatDoesNotExist(): void
    {
        $this->session = new Session(new ProcessDriver(['/nonexistent/tuink-app']), timeout: 0.5);
        $this->session->start();

        $this->session->assertExited();
        // 127 where sh reports a missing command, 126 where it reports it cannot run it (macOS)
        $this->assertContains($this->session->getDriver()->getExitCode(), [126, 127]);
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
}
