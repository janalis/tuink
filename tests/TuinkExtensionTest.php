<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\ServiceContainer\TuinkExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
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
        $this->assertSame(['php', 'bin/app', '--flag'], $config['process']['command']);
        $this->assertNull($config['process']['cwd']);
        $this->assertSame([], $config['process']['env']);
        $this->assertSame(2.0, $config['process']['probe_timeout']);
    }

    public function testADriverIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Configure at least one driver');

        $this->process([]);
    }

    public function testTheDefaultDriverMustBeConfigured(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The default driver "process" is not configured.');

        $this->process(['default_driver' => 'process', 'in_process' => ['factory' => 'factory.php']]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $tree = new TreeBuilder('tuink');
        (new TuinkExtension())->configure($tree->getRootNode());

        return (new Processor())->process($tree->buildTree(), [$config]);
    }
}
