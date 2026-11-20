<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Behat\Behat\Context\ServiceContainer\ContextExtension;
use Behat\Testwork\EventDispatcher\ServiceContainer\EventDispatcherExtension;
use Janalis\Tuink\Context\Argument\TuinkArgumentResolver;
use Janalis\Tuink\Context\Initializer\TuinkAwareInitializer;
use Janalis\Tuink\Listener\SessionListener;
use Janalis\Tuink\ServiceContainer\TuinkExtension;
use Janalis\Tuink\Tuink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

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

    public function testAProfileCommandReplacesTheDefaultOne(): void
    {
        $root = new ArrayNodeDefinition('tuink');
        new TuinkExtension()->configure($root);
        $config = new Processor()->process($root->getNode(forceRootNode: true), [
            ['process' => ['command' => ['php', 'bin/app'], 'env' => ['A' => '1']]],
            ['default_driver' => 'process', 'process' => ['command' => ['php', 'bin/other'], 'env' => ['B' => '2']]],
        ]);
        $process = self::section($config, name: 'process');

        $this->assertSame(['php', 'bin/other'], $process['command']);
        $this->assertSame(['A' => '1', 'B' => '2'], $process['env']);
    }

    public function testValuesAreNotContainerParameters(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('paths.base', value: '/base');
        /** @var array<string, mixed> $config */
        $config = $this->process([
            'process' => ['command' => ['app', '%workspace%/a', '%paths.base%', 'date', '+%Y-%m', '100%'], 'cwd' => '%workspace%', 'env' => ['HOME' => '%workspace%/home', 'N' => 1]],
        ]);
        new TuinkExtension()->load($container, $config);

        // What the compiled container hands the factory: parameters resolved, then "%%" unescaped
        $bag = $container->getParameterBag();
        $resolved = \array_map(static fn (mixed $argument): mixed => $bag->unescapeValue($bag->resolveValue($argument)), $container->getDefinition(TuinkExtension::MANAGER_ID)->getArguments());
        $this->assertSame('/base', $resolved[1]);
        $process = self::section(self::section($resolved, name: '0'), name: 'process');
        $this->assertSame(['app', '%workspace%/a', '%paths.base%', 'date', '+%Y-%m', '100%'], $process['command']);
        $this->assertSame('%workspace%', $process['cwd']);
        $this->assertSame(['HOME' => '%workspace%/home', 'N' => 1], $process['env']);
    }

    public function testTheServices(): void
    {
        $container = new ContainerBuilder();
        /** @var array<string, mixed> $config */
        $config = $this->process(['process' => ['command' => 'app']]);
        new TuinkExtension()->load($container, $config);

        $manager = $container->getDefinition(TuinkExtension::MANAGER_ID);
        $this->assertSame(Tuink::class, $manager->getClass());
        $this->assertSame([Tuink::class, 'fromConfig'], $manager->getFactory());
        $this->assertTrue($manager->isPublic());
        $services = [
            'tuink.context_initializer' => [TuinkAwareInitializer::class, ContextExtension::INITIALIZER_TAG],
            'tuink.argument_resolver' => [TuinkArgumentResolver::class, ContextExtension::ARGUMENT_RESOLVER_TAG],
            'tuink.session_listener' => [SessionListener::class, EventDispatcherExtension::SUBSCRIBER_TAG],
        ];
        foreach ($services as $id => [$class, $tag]) {
            $definition = $container->getDefinition($id);
            $this->assertSame($class, $definition->getClass(), $id);
            $this->assertTrue($definition->hasTag($tag), $id);
            $this->assertEquals([new Reference(TuinkExtension::MANAGER_ID)], $definition->getArguments(), $id);
        }
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
