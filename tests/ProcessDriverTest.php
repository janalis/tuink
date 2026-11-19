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
use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Exception\UnsupportedDriverException;
use Janalis\Tuink\Selector\Element;
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
        $pattern = \sys_get_temp_dir().'/tuink-*/probe.sock';
        $before = self::glob($pattern);
        $session = $this->demo();
        $session->find('input');
        $mine = \array_values(\array_diff(self::glob($pattern), $before));
        $this->assertCount(1, $mine);

        $session->stop();

        $this->assertFileDoesNotExist($mine[0]);
        $this->assertDirectoryDoesNotExist(\dirname($mine[0]));
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

    /**
     * @return list<string>
     */
    private static function glob(string $pattern): array
    {
        $paths = \glob($pattern);

        return false === $paths ? [] : $paths;
    }
}
