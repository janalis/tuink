<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Listener;

use Behat\Behat\EventDispatcher\Event\ExampleTested;
use Behat\Behat\EventDispatcher\Event\ScenarioTested;
use Behat\Gherkin\Node\TaggedNodeInterface;
use Behat\Testwork\EventDispatcher\Event\ExerciseCompleted;
use Janalis\Tuink\Tuink;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Picks the driver a scenario's tags ask for, and stops the application after it.
 *
 * A scenario or feature tagged with a configured driver name, like @process or
 *
 * @in_process, runs on that driver.
 */
final class SessionListener implements EventSubscriberInterface
{
    private const DRIVER_NAMES = ['in_process', 'process'];

    public function __construct(
        private readonly Tuink $tuink,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ScenarioTested::BEFORE => ['beforeScenario', 10],
            ExampleTested::BEFORE => ['beforeScenario', 10],
            ScenarioTested::AFTER => ['afterScenario', -10],
            ExampleTested::AFTER => ['afterScenario', -10],
            ExerciseCompleted::AFTER => ['afterExercise', -10],
        ];
    }

    public function beforeScenario(ScenarioTested $event): void
    {
        $this->tuink->restoreDefaults();

        $scenario = $event->getScenario();
        // A scenario tag overrides a feature tag
        foreach ([$scenario instanceof TaggedNodeInterface ? $scenario->getTags() : [], $event->getFeature()->getTags()] as $tags) {
            // Recent Gherkin versions keep the "@" in tag names
            $names = \array_map(static fn (string $tag): string => \ltrim($tag, characters: '@'), $tags);
            foreach (\array_diff(\array_intersect($names, self::DRIVER_NAMES), $this->tuink->getDriverNames()) as $missing) {
                throw new \LogicException(\sprintf('"%s" is tagged @%s, but the %s driver is not configured.', $scenario->getTitle() ?? $event->getFeature()->getTitle() ?? '', $missing, $missing));
            }
            $wanted = \array_values(\array_unique(\array_filter($names, $this->tuink->hasDriver(...))));
            if (\count($wanted) > 1) {
                throw new \LogicException(\sprintf('"%s" is tagged with more than one driver: @%s.', $scenario->getTitle() ?? $event->getFeature()->getTitle() ?? '', \implode(', @', $wanted)));
            }
            if ([] !== $wanted) {
                $this->tuink->useDriver($wanted[0]);

                return;
            }
        }
    }

    public function afterScenario(): void
    {
        $this->tuink->restoreDefaults();
    }

    public function afterExercise(): void
    {
        $this->tuink->reset();
    }
}
