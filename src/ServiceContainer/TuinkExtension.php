<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\ServiceContainer;

use Behat\Behat\Context\ServiceContainer\ContextExtension;
use Behat\Testwork\EventDispatcher\ServiceContainer\EventDispatcherExtension;
use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Janalis\Tuink\Context\Argument\TuinkArgumentResolver;
use Janalis\Tuink\Context\Initializer\TuinkAwareInitializer;
use Janalis\Tuink\Listener\SessionListener;
use Janalis\Tuink\Tuink;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers tuink in Behat.
 *
 *     extensions:
 *         Janalis\Tuink\ServiceContainer\TuinkExtension:
 *             default_driver: in_process
 *             in_process:
 *                 factory: tests/tui-factory.php
 *             process:
 *                 command: [php, bin/app]
 */
final class TuinkExtension implements Extension
{
    public const MANAGER_ID = 'tuink.manager';

    public function getConfigKey(): string
    {
        return 'tuink';
    }

    public function initialize(ExtensionManager $extensionManager): void
    {
    }

    public function configure(ArrayNodeDefinition $builder): void
    {
        $builder
            ->children()
            ->scalarNode('default_driver')
            ->info('The driver scenarios run on, unless tagged @in_process or @process; defaults to the only configured one, or in_process')
            ->defaultNull()
            ->end()
            ->integerNode('columns')
            ->min(1)
            ->defaultValue(80)
            ->end()
            ->integerNode('rows')
            ->min(1)
            ->defaultValue(24)
            ->end()
            ->floatNode('timeout')
            ->info('Seconds a step waits for the application to show what it expects')
            ->min(0)
            ->defaultValue(2.0)
            ->end()
            ->floatNode('quiet_period')
            ->info('Seconds without output after which the application counts as settled; defaults to 0.02 in process, 0.05 for a process')
            ->min(0)
            ->defaultNull()
            ->end()
            ->scalarNode('snapshots')
            ->info('Directory of screen snapshots, relative to the Behat base path')
            ->defaultValue('features/__snapshots__')
            ->end()
            ->arrayNode('in_process')
            ->info('Runs the application in the Behat process, on a VirtualTerminal')
            ->children()
            ->scalarNode('factory')
            ->info('PHP file returning a callable(VirtualTerminal $terminal): Tui, relative to the Behat base path')
            ->isRequired()
            ->cannotBeEmpty()
            ->end()
            ->end()
            ->end()
            ->arrayNode('process')
            ->info('Runs the application as a process in a pseudo-terminal')
            ->children()
            ->arrayNode('command')
            ->info('The command line, as a list of arguments or a string split on spaces')
            ->beforeNormalization()
            ->ifString()
            ->then(static fn (string $command): array => \preg_split('/\s+/', \trim($command), limit: -1, flags: \PREG_SPLIT_NO_EMPTY))
            ->end()
            ->isRequired()
            ->requiresAtLeastOneElement()
            ->scalarPrototype()
            ->end()
            ->end()
            ->scalarNode('cwd')
            ->info('Working directory, relative to the Behat base path')
            ->defaultNull()
            ->end()
            ->arrayNode('env')
            ->useAttributeAsKey('name')
            ->scalarPrototype()
            ->end()
            ->end()
            ->floatNode('probe_timeout')
            ->info('Seconds to wait for the application\'s TuiProbe to connect and answer')
            ->min(0)
            ->defaultValue(2.0)
            ->end()
            ->end()
            ->end()
            ->end()
            ->validate()
            ->ifTrue(static fn (array $c): bool => !\array_key_exists('in_process', $c) && !\array_key_exists('process', $c))
            ->thenInvalid('Configure at least one driver: "in_process" or "process".')
            ->end()
            ->validate()
            ->always(static function (array $c): array {
                $fallback = \array_key_exists('in_process', $c) ? 'in_process' : 'process';
                $default = \is_string($c['default_driver'] ?? null) ? $c['default_driver'] : $fallback;
                if (!\array_key_exists($default, $c) || !\in_array($default, ['in_process', 'process'], strict: true)) {
                    throw new \InvalidArgumentException(\sprintf('The default driver "%s" is not configured.', $default));
                }
                $c['default_driver'] = $default;

                return $c;
            })
            ->end();
    }

    public function load(ContainerBuilder $container, array $config): void
    {
        $manager = new Definition(Tuink::class, [$config, '%paths.base%']);
        $manager->setFactory([Tuink::class, 'fromConfig']);
        $manager->setPublic(true);
        $container->setDefinition(self::MANAGER_ID, $manager);

        $initializer = new Definition(TuinkAwareInitializer::class, [new Reference(self::MANAGER_ID)]);
        $initializer->addTag(ContextExtension::INITIALIZER_TAG);
        $container->setDefinition('tuink.context_initializer', $initializer);

        $resolver = new Definition(TuinkArgumentResolver::class, [new Reference(self::MANAGER_ID)]);
        $resolver->addTag(ContextExtension::ARGUMENT_RESOLVER_TAG);
        $container->setDefinition('tuink.argument_resolver', $resolver);

        $listener = new Definition(SessionListener::class, [new Reference(self::MANAGER_ID)]);
        $listener->addTag(EventDispatcherExtension::SUBSCRIBER_TAG);
        $container->setDefinition('tuink.session_listener', $listener);
    }

    public function process(ContainerBuilder $container): void
    {
    }
}
