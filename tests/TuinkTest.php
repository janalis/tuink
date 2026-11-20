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
use Janalis\Tuink\Driver\ProcessDriver;
use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Tuink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TuinkTest extends TestCase
{
    public function testDriversFromConfig(): void
    {
        $tuink = $this->tuink();

        $this->assertSame(['in_process', 'process'], $tuink->getDriverNames());
        $this->assertSame('in_process', $tuink->getDriverName());
        $this->assertInstanceOf(InProcessDriver::class, $tuink->getSession()->getDriver());

        $tuink->useDriver('process');
        $this->assertInstanceOf(ProcessDriver::class, $tuink->getSession()->getDriver());
    }

    public function testSessionIsKeptUntilReset(): void
    {
        $tuink = $this->tuink();
        $session = $tuink->getSession();

        $this->assertSame($session, $tuink->getSession());
        $tuink->useDriver('in_process');
        // Using the current driver keeps the session.
        $this->assertSame($session, $tuink->getSession());

        $tuink->reset();
        $this->assertNotSame($session, $tuink->getSession());
    }

    public function testTheFactoryFileIsRequiredOnce(): void
    {
        $file = \sys_get_temp_dir().'/tuink-factory-'.\bin2hex(\random_bytes(4)).'.php';
        $function = 'tuink_factory_'.\bin2hex(\random_bytes(4));
        \file_put_contents(
            $file,
            "<?php\nfunction {$function}() {}\nreturn static fn (\\Symfony\\Component\\Tui\\Terminal\\VirtualTerminal \$t) => new \\Symfony\\Component\\Tui\\Tui(terminal: \$t);\n",
        );

        try {
            $tuink = $this->tuink(factory: $file);
            for ($i = 0; $i < 2; ++$i) {
                $tuink->getSession()->start();
                $tuink->reset();
            }
            $this->assertTrue(\function_exists($function));
        } finally {
            \unlink($file);
        }
    }

    public function testRestoreDefaults(): void
    {
        $tuink = $this->tuink();
        $tuink->useDriver('process');
        $tuink->restoreDefaults();

        $this->assertSame('in_process', $tuink->getDriverName());
    }

    public function testUnknownDriver(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The driver "ssh" is not configured; configured drivers: "in_process", "process".');

        $this->tuink()->useDriver('ssh');
    }

    public function testSizeComesFromConfig(): void
    {
        $session = $this->tuink(columns: 100, rows: 30)->getSession();

        $this->assertSame(100, $session->getColumns());
        $this->assertSame(30, $session->getRows());
    }

    public function testDefaults(): void
    {
        $session = new Tuink(['in_process' => static fn (): InProcessDriver => new InProcessDriver(static fn () => null)], defaultDriver: 'in_process')->getSession();

        $this->assertSame(80, $session->getColumns());
        $this->assertSame(24, $session->getRows());
        $this->assertSame(2.0, $session->getTimeout());
        // A driver without its own quiet period gets the process one
        $this->assertSame(Tuink::PROCESS_QUIET_PERIOD, $session->getQuietPeriod());
    }

    public function testQuietPeriodsPerDriver(): void
    {
        $tuink = $this->tuink();
        $this->assertSame(Tuink::IN_PROCESS_QUIET_PERIOD, $tuink->getSession()->getQuietPeriod());
        $tuink->useDriver('process');
        $this->assertSame(Tuink::PROCESS_QUIET_PERIOD, $tuink->getSession()->getQuietPeriod());

        $tuink = $this->tuink(quietPeriod: 0.3);
        $this->assertSame(0.3, $tuink->getSession()->getQuietPeriod());
        $tuink->useDriver('process');
        $this->assertSame(0.3, $tuink->getSession()->getQuietPeriod());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function factoryPaths(): iterable
    {
        yield 'relative' => ['missing.php', \dirname(__DIR__).'/missing.php'];
        yield 'absolute' => ['/missing.php', '/missing.php'];
        yield 'windows drive' => ['C:/missing.php', 'C:/missing.php'];
        yield 'windows backslash' => ['C:\\missing.php', 'C:\\missing.php'];
        yield 'a drive letter further in is relative' => ['dir/C:/missing.php', \dirname(__DIR__).'/dir/C:/missing.php'];
        yield 'the workspace' => ['%workspace%/missing.php', '%workspace%/missing.php'];
        yield 'the workspace further in is relative' => ['dir/%workspace%/missing.php', \dirname(__DIR__).'/dir/%workspace%/missing.php'];
    }

    #[DataProvider('factoryPaths')]
    public function testPathsAreResolvedFromTheBasePath(string $factory, string $resolved): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('The in-process factory file "%s" does not exist.', $resolved));

        $this->tuink(factory: $factory)->getSession();
    }

    public function testTheProcessTemporaryDirectoryIsResolvedFromTheBasePath(): void
    {
        $tuink = $this->tuink(tempDir: 'var/tmp');
        $tuink->useDriver('process');
        $driver = $tuink->getSession()->getDriver();

        $this->assertSame(\dirname(__DIR__).'/var/tmp', new \ReflectionProperty(ProcessDriver::class, 'tempDir')->getValue($driver));
    }

    public function testResetStopsTheApplicationAndRemovesTheWorkspace(): void
    {
        $tuink = $this->tuink();
        $session = $tuink->getSession();
        $session->start();
        $workspace = $session->getWorkspace()->path;
        $tuink->reset();

        $this->assertFalse($session->getDriver()->isStarted());
        $this->assertDirectoryDoesNotExist($workspace);
    }

    public function testRestoreDefaultsStartsAfresh(): void
    {
        $tuink = $this->tuink();
        $session = $tuink->getSession();
        $session->start();
        $tuink->restoreDefaults();

        $this->assertFalse($session->getDriver()->isStarted());
        $this->assertNotSame($session, $tuink->getSession());
    }

    private function tuink(int $columns = 80, int $rows = 24, string $factory = 'example/factory.php', ?float $quietPeriod = null, ?string $tempDir = null): Tuink
    {
        return Tuink::fromConfig(
            [
                'default_driver' => 'in_process',
                'columns' => $columns,
                'rows' => $rows,
                'timeout' => 1.0,
                'quiet_period' => $quietPeriod,
                'snapshots' => null,
                'in_process' => ['factory' => $factory],
                'process' => ['command' => ['php', 'example/bin/demo'], 'cwd' => '.', 'env' => [], 'probe_timeout' => 2.0, 'temp_dir' => $tempDir],
            ],
            \dirname(__DIR__),
        );
    }
}
