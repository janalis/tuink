<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Driver;

use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Launch;
use Janalis\Tuink\Selector\WidgetTree;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Runs the application in the test process, on a VirtualTerminal.
 *
 * The factory receives the terminal and the scenario's Launch, and returns the
 * application's Tui without starting it, or an InProcessApplicationInterface that also
 * boots and shuts the application down around the Tui's run. The Launch's variables are set for the
 * run in putenv(), $_SERVER and $_ENV (Symfony Process gives its children the
 * variables of the last two), and restored after it. The driver starts it and turns the Revolt event loop in short
 * slices, so timers, intervals and async work progress as they would under
 * Tui::run(), and the Tui itself stays reachable for focus and widget checks.
 */
final class InProcessDriver implements DriverInterface, WidgetTreeProviderInterface
{
    /** @var \Closure(VirtualTerminal, Launch): mixed */
    private readonly \Closure $factory;
    private ?VirtualTerminal $terminal = null;
    private ?Tui $tui = null;
    private ?InProcessApplicationInterface $application = null;

    /**
     * Whether the application booted and was not shut down yet.
     */
    private bool $booted = false;
    private ?ScreenBuffer $screen = null;
    private TerminalObserver $observer;
    /** @var array<string, true> Event loop callbacks that existed before the application */
    private array $foreignCallbacks = [];
    /** @var array<string, array{string|false, ?array{mixed}, ?array{mixed}}> What getenv(), $_SERVER and $_ENV held, to restore at stop */
    private array $replacedEnv = [];

    /**
     * @param callable(VirtualTerminal, Launch): mixed $factory Returns the Tui or an InProcessApplicationInterface; anything else is reported at start
     */
    public function __construct(callable $factory)
    {
        $this->factory = $factory(...);
        $this->observer = new TerminalObserver();
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
     * @return callable(VirtualTerminal, Launch): mixed
     */
    public static function loadFactory(string $path): callable
    {
        if (!\is_file($path)) {
            throw new DriverException(\sprintf('The in-process factory file "%s" does not exist.', $path));
        }

        return self::callableFrom((static fn (string $path): mixed => require $path)($path), $path);
    }

    private static function callableFrom(mixed $factory, string $path): callable
    {
        if (!\is_callable($factory)) {
            throw new DriverException(\sprintf(
                'The in-process factory file "%s" must return a callable(VirtualTerminal, Launch): Tui|InProcessApplicationInterface, "%s" returned.',
                $path,
                \get_debug_type($factory),
            ));
        }

        return $factory;
    }

    private static function built(mixed $built): Tui|InProcessApplicationInterface
    {
        if ($built instanceof Tui || $built instanceof InProcessApplicationInterface) {
            return $built;
        }

        throw new DriverException(\sprintf('The in-process factory must return a "%s" or an "%s", "%s" returned.', Tui::class, InProcessApplicationInterface::class, \get_debug_type($built)));
    }

    public function getName(): string
    {
        return 'in_process';
    }

    public function start(int $columns, int $rows, Launch $launch = new Launch()): void
    {
        if (null !== $this->tui) {
            throw new DriverException('The application is already started.');
        }

        $this->foreignCallbacks = \array_fill_keys(EventLoop::getIdentifiers(), value: true);
        $launch = $launch->resolved();
        foreach ($launch->env as $name => $value) {
            $this->replacedEnv[$name] = [\getenv($name), self::held($_SERVER, $name), self::held($_ENV, $name)];
            \putenv($name.'='.$value);
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
        }
        $terminal = new VirtualTerminal($columns, $rows);
        $terminal->setKittyProtocolActive($launch->kitty);
        try {
            $built = self::built(($this->factory)($terminal, $launch));
            $tui = $built instanceof InProcessApplicationInterface ? $built->getTui() : $built;
            if ($tui->getTerminal() !== $terminal) {
                throw new DriverException('The in-process factory must build the Tui on the VirtualTerminal it receives.');
            }
        } catch (\Throwable $e) {
            $this->restoreEnv();

            throw $e;
        }

        $this->terminal = $terminal;
        $this->tui = $tui;
        $this->application = $built instanceof InProcessApplicationInterface ? $built : null;
        $this->screen = new ScreenBuffer($columns, $rows);
        $this->observer = new TerminalObserver();
        // As an entry point does: set up, then run. A boot that throws cleans up after itself, as it would before run()
        try {
            $this->application?->boot();
            $this->booted = true;
        } catch (\Throwable $e) {
            $this->application = null;
            $this->release();

            throw $e;
        }
        try {
            $tui->start();
        } catch (\Throwable $e) {
            // Booted but never run: the start may have got far enough to be stopped, and the shutdown still owes what the
            // boot started
            $this->stop();

            throw $e;
        }
    }

    public function stop(): void
    {
        try {
            if (null !== $this->tui && $this->tui->isRunning()) {
                $this->tui->stop();
            }
        } finally {
            $this->shutDown();
        }
    }

    /**
     * What the entry point does once run() returned, whether or not stopping the Tui threw: the application saves and
     * stops what it started; then what it left on the loop goes.
     */
    private function shutDown(): void
    {
        try {
            $this->shutDownApplication();
        } finally {
            $this->application = null;
            $this->release();
        }
    }

    /**
     * Runs the code after run() once: when the Tui quits by itself, as on a quit key, or at the latest when it is stopped.
     */
    private function shutDownApplication(): void
    {
        if (!$this->booted) {
            return;
        }
        $this->booted = false;
        $this->application?->shutdown();
    }

    /**
     * Cancels what the application left on the loop, and forgets it.
     */
    private function release(): void
    {
        // The loop is shared by the whole run: timers the application left behind must not fire in the next scenario
        if (null !== $this->tui) {
            foreach (EventLoop::getIdentifiers() as $id) {
                if (\array_key_exists($id, $this->foreignCallbacks)) {
                    continue;
                }

                EventLoop::cancel($id);
            }
        }

        $this->tui = null;
        $this->terminal = null;
        $this->restoreEnv();
    }

    public function isStarted(): bool
    {
        return null !== $this->tui;
    }

    public function isRunning(): bool
    {
        return null !== $this->tui && $this->tui->isRunning();
    }

    /**
     * A virtual terminal has no line mode: the application gets every key.
     */
    public function isInLineMode(): bool
    {
        return false;
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
        // ScreenBuffer has a fixed size; a resize repaints the whole frame, so a fresh buffer sees all of it, and the
        // repaint clears whatever was written before it
        $this->screen = new ScreenBuffer($columns, $rows);
        $terminal->simulateResize($columns, $rows);
    }

    public function pump(float $seconds): bool
    {
        $terminal = $this->terminal();
        // The Tui's own ticker runs on the loop, as under Tui::run(): it renders what input and timers changed
        self::runLoop(\max(0.0, $seconds));
        // Run() returned: what the entry point does after it, like saving, shows before the scenario ends
        if (!$this->tui()->isRunning()) {
            $this->shutDownApplication();
        }

        return $this->flushOutput($terminal);
    }

    public function getObserver(): TerminalObserver
    {
        return $this->observer;
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
     * What the factory returned when it was an InProcessApplicationInterface, for steps that reach into the application.
     */
    public function getApplication(): ?InProcessApplicationInterface
    {
        $this->tui();

        return $this->application;
    }

    /**
     * The application's Tui, for steps that inspect widgets and focus.
     */
    public function getTui(): Tui
    {
        return $this->tui();
    }

    private function flushOutput(VirtualTerminal $terminal): bool
    {
        $output = $terminal->consumeOutput();
        if ('' === $output) {
            return false;
        }
        $this->getScreen()->write($output);
        $this->observer->observe($output);

        return true;
    }

    private function restoreEnv(): void
    {
        foreach ($this->replacedEnv as $name => [$value, $server, $env]) {
            \putenv(false === $value ? $name : $name.'='.$value);
            self::restore($_SERVER, $name, $server);
            self::restore($_ENV, $name, $env);
        }
        $this->replacedEnv = [];
    }

    /**
     * @param array<mixed>  $variables
     * @param ?array{mixed} $held      What held() returned
     */
    private static function restore(array &$variables, string $name, ?array $held): void
    {
        unset($variables[$name]);
        if (null !== $held) {
            $variables[$name] = $held[0];
        }
    }

    /**
     * The value under the name, wrapped so that null tells a missing entry from a null one.
     *
     * @param array<mixed> $variables
     *
     * @return ?array{mixed}
     */
    private static function held(array $variables, string $name): ?array
    {
        return \array_key_exists($name, $variables) ? [$variables[$name]] : null;
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
        $id = EventLoop::delay($seconds, $suspension->resume(...));
        try {
            $suspension->suspend();
        } finally {
            EventLoop::cancel($id);
        }
    }
}
