<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\ServiceContainer\TuinkExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class TuinkExtensionTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = $this->process(['in_process' => ['factory' => 'factory.php']]);

        $this->assertSame('in_process', $config['default_driver']);
        $this->assertSame(80, $config['columns']);
        $this->assertSame(24, $config['rows']);
        $this->assertSame(2.0, $config['timeout']);
        $this->assertNull($config['quiet_period']);
        $this->assertSame('features/__snapshots__', $config['snapshots']);
        $this->assertArrayNotHasKey('process', $config);
    }

    public function testTheOnlyDriverIsTheDefault(): void
    {
        $config = $this->process(['process' => ['command' => 'php  bin/app --flag']]);

        $this->assertSame('process', $config['default_driver']);
        $process = self::section($config, name: 'process');
        $this->assertSame(['php', 'bin/app', '--flag'], $process['command']);
        $this->assertSame('.', $process['cwd']);
        $this->assertSame([], $process['env']);
        $this->assertSame(2.0, $process['probe_timeout']);
        $this->assertNull($process['temp_dir']);
    }

    public function testADriverIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('Configure at least one driver');

        $this->process([]);
    }

    public function testTheDefaultDriverMustBeConfigured(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('The default driver "process" is not configured.');

        $this->process(['default_driver' => 'process', 'in_process' => ['factory' => 'factory.php']]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function process(array $config): array
    {
        $root = new ArrayNodeDefinition('tuink');
        new TuinkExtension()->configure($root);

        return new Processor()->process($root->getNode(forceRootNode: true), [$config]);
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validBounds(): iterable
    {
        $process = ['process' => ['command' => 'php app']];
        yield 'one column' => [['columns' => 1] + $process];
        yield 'one row' => [['rows' => 1] + $process];
        yield 'no timeout' => [['timeout' => 0.0] + $process];
        yield 'no quiet period' => [['quiet_period' => 0.0] + $process];
        yield 'no probe timeout' => [['process' => ['command' => 'php app', 'probe_timeout' => 0.0]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('validBounds')]
    public function testValidBounds(array $config): void
    {
        $this->assertNotSame([], $this->process($config));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBounds(): iterable
    {
        $process = ['process' => ['command' => 'php app']];
        yield 'no column' => [['columns' => 0] + $process];
        yield 'no row' => [['rows' => 0] + $process];
        yield 'negative timeout' => [['timeout' => -0.1] + $process];
        yield 'negative quiet period' => [['quiet_period' => -0.1] + $process];
        yield 'negative probe timeout' => [['process' => ['command' => 'php app', 'probe_timeout' => -0.1]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidBounds')]
    public function testInvalidBounds(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process($config);
    }

    public function testACommandStringIsSplitOnBlanks(): void
    {
        $process = self::section($this->process(['process' => ['command' => " php\tbin/app  "]]), name: 'process');

        $this->assertSame(['php', 'bin/app'], $process['command']);
    }

    private static function section(array $config, string $name): array
    {
        return \is_array($config[$name] ?? null) ? $config[$name] : throw new \UnexpectedValueException(\sprintf('No "%s" section.', $name));
    }
}
