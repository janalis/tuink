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
 * application's Tui without starting it. The Launch's variables are set for the
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
    private ?ScreenBuffer $screen = null;
    private TerminalObserver $observer;
    /** @var array<string, true> Event loop callbacks that existed before the application */
    private array $foreignCallbacks = [];
    /** @var array<string, array{string|false, ?array{mixed}, ?array{mixed}}> What getenv(), $_SERVER and $_ENV held, to restore at stop */
    private array $replacedEnv = [];

    /**
     * @param callable(VirtualTerminal, Launch): mixed $factory Returns the Tui; anything else is reported at start
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
            throw new DriverException(\sprintf('The in-process factory file "%s" must return a callable(VirtualTerminal, Launch): Tui, "%s" returned.', $path, \get_debug_type($factory)));
        }

        return $factory;
    }

    private static function tuiFrom(mixed $tui): Tui
    {
        if (!$tui instanceof Tui) {
            throw new DriverException(\sprintf('The in-process factory must return a "%s", "%s" returned.', Tui::class, \get_debug_type($tui)));
        }

        return $tui;
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
            $tui = self::tuiFrom(($this->factory)($terminal, $launch));
            if ($tui->getTerminal() !== $terminal) {
                throw new DriverException('The in-process factory must build the Tui on the VirtualTerminal it receives.');
            }
        } catch (\Throwable $e) {
            $this->restoreEnv();

            throw $e;
        }

        $this->terminal = $terminal;
        $this->tui = $tui;
        $this->screen = new ScreenBuffer($columns, $rows);
        $this->observer = new TerminalObserver();
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
