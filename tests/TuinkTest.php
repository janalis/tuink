<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Driver\InProcessDriver;
use Janalis\Tuink\Driver\ProcessDriver;
use Janalis\Tuink\Tuink;
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
        $this->assertSame($session, $tuink->getSession(), 'Using the current driver keeps the session.');

        $tuink->reset();
        $this->assertNotSame($session, $tuink->getSession());
    }

    public function testTheFactoryFileIsRequiredOnce(): void
    {
        $file = sys_get_temp_dir().'/tuink-factory-'.bin2hex(random_bytes(4)).'.php';
        $function = 'tuink_factory_'.bin2hex(random_bytes(4));
        file_put_contents($file, "<?php\nfunction {$function}() {}\nreturn static fn (\\Symfony\\Component\\Tui\\Terminal\\VirtualTerminal \$t) => new \\Symfony\\Component\\Tui\\Tui(terminal: \$t);\n");

        try {
            $tuink = $this->tuink(['in_process' => ['factory' => $file]]);
            for ($i = 0; $i < 2; ++$i) {
                $tuink->getSession()->start();
                $tuink->reset();
            }
            $this->assertTrue(\function_exists($function));
        } finally {
            unlink($file);
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
        $this->expectExceptionMessage('The driver "ssh" is not configured; configured drivers: "in_process", "process".');

        $this->tuink()->useDriver('ssh');
    }

    public function testSizeComesFromConfig(): void
    {
        $session = $this->tuink(['columns' => 100, 'rows' => 30])->getSession();

        $this->assertSame(100, $session->getColumns());
        $this->assertSame(30, $session->getRows());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function tuink(array $config = []): Tuink
    {
        return Tuink::fromConfig($config + [
            'default_driver' => 'in_process',
            'columns' => 80,
            'rows' => 24,
            'timeout' => 1.0,
            'quiet_period' => null,
            'snapshots' => null,
            'in_process' => ['factory' => 'example/factory.php'],
            'process' => ['command' => ['php', 'example/bin/demo'], 'cwd' => null, 'env' => []],
        ], \dirname(__DIR__));
    }
}
