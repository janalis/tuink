<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs Behat itself, to see what a failing scenario reports.
 */
final class BehatTest extends TestCase
{
    #[RequiresFunction('proc_open')]
    public function testFailingStepsReportTheScreen(): void
    {
        $root = \dirname(__DIR__);
        $process = new Process(
            [self::php(), $root.'/vendor/bin/behat', '--no-colors', '--config', $root.'/tests/Fixtures/failing/behat.php'],
            $root.'/tests/Fixtures/failing',
        );
        $process->setTimeout(60);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('The text "Goodbye" was not found on the screen.', $output);
        $this->assertStringContainsString('Screen (80x24, in_process driver, running):', $output);
        $this->assertMatchesRegularExpression('/\b1 \| Tuink demo\b/', $output);
        $this->assertStringContainsString(
            'The application did not connect to the tuink probe. CSS selectors on the process driver need it to call Janalis\\Tuink\\Probe\\TuiProbe::install($tui) before run(); or tag the scenario @in_process.',
            $output,
        );
        $this->assertStringContainsString('2 scenarios (2 failed)', $output);
    }

    /**
     * The self-test features must use every step TuiContext defines.
     */
    #[RequiresFunction('proc_open')]
    public function testTheFeaturesUseEveryStep(): void
    {
        $root = \dirname(__DIR__);
        $process = new Process([self::php(), $root.'/vendor/bin/behat', '--no-colors', '--dry-run', '--print-unused-definitions', '--format=progress'], $root);
        $process->setTimeout(60);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('No unused definitions', $output);
    }

    #[RequiresFunction('proc_open')]
    public function testATagForAnUnconfiguredDriverFails(): void
    {
        $code = null;
        $output = $this->behat('unconfigured', $code);

        $this->assertNotSame(0, $code, $output);
        $this->assertStringContainsString('is tagged @process, but the process driver is not configured.', $output);
    }

    private function behat(string $fixture, ?int &$code = null): string
    {
        $root = \dirname(__DIR__);
        $process = new Process(
            [self::php(), $root.'/vendor/bin/behat', '--no-colors', '--config', $root.'/tests/Fixtures/'.$fixture.'/behat.php'],
            $root.'/tests/Fixtures/'.$fixture,
        );
        $process->setTimeout(60);
        $process->run();
        $code = $process->getExitCode();

        return $process->getOutput().$process->getErrorOutput();
    }

    private static function php(): string
    {
        $php = new PhpExecutableFinder()->find(includeArgs: false);

        return false === $php ? 'php' : $php;
    }
}
