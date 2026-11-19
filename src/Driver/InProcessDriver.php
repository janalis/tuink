<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Driver;

use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Selector\WidgetTree;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Runs the application in the test process, on a VirtualTerminal.
 *
 * The factory receives the terminal and returns the application's Tui without
 * starting it. The driver starts it and turns the Revolt event loop in short
 * slices, so timers, intervals and async work progress as they would under
 * Tui::run(), and the Tui itself stays reachable for focus and widget checks.
 */
final class InProcessDriver implements DriverInterface, WidgetTreeProvider
{
    /** @var \Closure(VirtualTerminal): mixed */
    private readonly \Closure $factory;
    private ?VirtualTerminal $terminal = null;
    private ?Tui $tui = null;
    private ?ScreenBuffer $screen = null;
    /** @var array<string, true> Event loop callbacks that existed before the application */
    private array $foreignCallbacks = [];

    /**
     * @param callable(VirtualTerminal): mixed $factory Returns the Tui; anything else is reported at start
     */
    public function __construct(callable $factory)
    {
        $this->factory = $factory(...);
    }

    /**
     * Builds a driver from a PHP file that returns the factory callable.
     */
    public static function fromFile(string $path): self
    {
        return new self(self::loadFactory($path));
    }

    /**
     * Requires a PHP file that returns the factory callable.
     *
     * @return callable(VirtualTerminal): mixed
     */
    public static function loadFactory(string $path): callable
    {
        if (!is_file($path)) {
            throw new DriverException(\sprintf('The in-process factory file "%s" does not exist.', $path));
        }

        $factory = (static fn (string $path): mixed => require $path)($path);
        if (!\is_callable($factory)) {
            throw new DriverException(\sprintf('The in-process factory file "%s" must return a callable(VirtualTerminal): Tui, "%s" returned.', $path, get_debug_type($factory)));
        }

        return $factory;
    }

    public function getName(): string
    {
        return 'in_process';
    }

    public function start(int $columns, int $rows): void
    {
        if (null !== $this->tui) {
            throw new DriverException('The application is already started.');
        }

        $this->foreignCallbacks = array_fill_keys(EventLoop::getIdentifiers(), true);
        $terminal = new VirtualTerminal($columns, $rows);
        $tui = ($this->factory)($terminal);
        if (!$tui instanceof Tui) {
            throw new DriverException(\sprintf('The in-process factory must return a "%s", "%s" returned.', Tui::class, get_debug_type($tui)));
        }
        if ($tui->getTerminal() !== $terminal) {
            throw new DriverException('The in-process factory must build the Tui on the VirtualTerminal it receives.');
        }

        $this->terminal = $terminal;
        $this->tui = $tui;
        $this->screen = new ScreenBuffer($columns, $rows);
        $tui->start();
    }

    public function stop(): void
    {
        if (null !== $this->tui && $this->tui->isRunning()) {
            $this->tui->stop();
        }

        // The loop is shared by the whole run: timers the application left behind must not fire in the next scenario
        if (null !== $this->tui) {
            foreach (EventLoop::getIdentifiers() as $id) {
                if (!isset($this->foreignCallbacks[$id])) {
                    EventLoop::cancel($id);
                }
            }
        }

        $this->tui = null;
        $this->terminal = null;
    }

    public function isStarted(): bool
    {
        return null !== $this->tui;
    }

    public function isRunning(): bool
    {
        return null !== $this->tui && $this->tui->isRunning();
    }

    public function getExitCode(): ?int
    {
        return null;
    }

    public function send(string $bytes): void
    {
        $this->terminal()->simulateInput($bytes);
    }

    public function resize(int $columns, int $rows): void
    {
        $terminal = $this->terminal();
        $this->flushOutput();
        // ScreenBuffer has a fixed size; a resize repaints the whole frame, so a fresh buffer sees all of it
        $this->screen = new ScreenBuffer($columns, $rows);
        $terminal->simulateResize($columns, $rows);
    }

    public function pump(float $seconds): bool
    {
        $tui = $this->tui();
        if ($tui->isRunning()) {
            $tui->tick();
        }

        self::runLoop(max(0.0, $seconds));

        if ($tui->isRunning()) {
            $tui->tick();
        }

        return $this->flushOutput();
    }

    public function getScreen(): ScreenBuffer
    {
        return $this->screen ?? throw new DriverException('The application is not started.');
    }

    public function getWidgetTree(): WidgetTree
    {
        return WidgetTree::fromTui($this->tui());
    }

    public function focusWidget(int $index): void
    {
        $tui = $this->tui();
        $widget = WidgetTree::fromTui($tui)->widgets[$index] ?? throw new DriverException(\sprintf('No widget has the index %d.', $index));
        $tui->setFocus($widget);
    }

    /**
     * The application's Tui, for steps that inspect widgets and focus.
     */
    public function getTui(): Tui
    {
        return $this->tui();
    }

    private function flushOutput(): bool
    {
        if (null === $this->terminal || null === $this->screen) {
            return false;
        }

        $output = $this->terminal->consumeOutput();
        if ('' === $output) {
            return false;
        }
        $this->screen->write($output);

        return true;
    }

    private function tui(): Tui
    {
        return $this->tui ?? throw new DriverException('The application is not started.');
    }

    private function terminal(): VirtualTerminal
    {
        return $this->terminal ?? throw new DriverException('The application is not started.');
    }

    /**
     * Turns the event loop until $seconds passed, running whatever is due meanwhile.
     */
    private static function runLoop(float $seconds): void
    {
        $suspension = EventLoop::getSuspension();
        $id = EventLoop::delay($seconds, static fn () => $suspension->resume());
        try {
            $suspension->suspend();
        } finally {
            EventLoop::cancel($id);
        }
    }
}
