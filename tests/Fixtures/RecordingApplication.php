<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests\Fixtures;

use Janalis\Tuink\Driver\InProcessApplicationInterface;
use Symfony\Component\Tui\Tui;

/**
 * Notes when the driver boots and shuts it down, whether the Tui ran then, and what the environment held.
 */
final class RecordingApplication implements InProcessApplicationInterface
{
    /**
     * @param list<string> $calls
     */
    public function __construct(
        private readonly Tui $tui,
        private array &$calls,
        private readonly ?\Closure $onBoot = null,
        private readonly ?\Closure $onShutdown = null,
    ) {
    }

    public function getTui(): Tui
    {
        return $this->tui;
    }

    public function boot(): void
    {
        $this->record('boot');
        if (null !== $this->onBoot) {
            ($this->onBoot)();
        }
    }

    public function shutdown(): void
    {
        $this->record('shutdown');
        if (null !== $this->onShutdown) {
            ($this->onShutdown)();
        }
    }

    private function record(string $what): void
    {
        $this->calls[] = \sprintf('%s: %s, %s', $what, $this->tui->isRunning() ? 'running' : 'not running', (string) \getenv('TUINK_TEST_APP'));
    }
}
