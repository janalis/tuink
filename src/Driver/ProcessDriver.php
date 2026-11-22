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
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Terminal\ScreenBuffer;

/**
 * Runs the application as its own process, in a pseudo-terminal.
 *
 * This is the black-box driver: the real entry point boots, puts the terminal
 * in raw mode, reads keys from the tty and paints with escape sequences, which
 * the driver folds into a ScreenBuffer. It needs PTY support (Linux, macOS).
 */
final class ProcessDriver implements DriverInterface, WidgetTreeProviderInterface
{
    /** How long pump() sleeps between reads of the application's output */
    private const POLL_MICROSECONDS = 5_000;

    private ?RunningProcess $running = null;
    private ?ScreenBuffer $screen = null;
    private TerminalObserver $observer;
    private bool $kitty = false;
    /** How many kitty keyboard protocol queries the driver answered */
    private int $kittyAnswers = 0;

    /**
     * @param list<string>          $command      The command line, as an argument list; a scenario's arguments follow it, and
     *                                            "%workspace%" in it, as in $cwd and $env, is the scenario's workspace
     * @param string|null           $cwd          The working directory; "%workspace%" in it is the scenario's workspace
     * @param array<string, string> $env          Extra environment variables
     * @param float                 $probeTimeout Seconds to wait for the application's TuiProbe to connect and answer
     * @param string|null           $tempDir      Where the probe socket and the terminal name go, the system's temporary
     *                                            directory by default; a Unix socket path must stay under about 100 bytes
     */
    public function __construct(
        private readonly array $command,
        private readonly ?string $cwd = null,
        private readonly array $env = [],
        private readonly float $probeTimeout = 2.0,
        private readonly ?string $tempDir = null,
    ) {
        if ([] === $command) {
            throw new DriverException('The process driver needs a command.');
        }
        $this->observer = new TerminalObserver();
    }

    public function getName(): string
    {
        return 'process';
    }

    public function start(int $columns, int $rows, Launch $launch = new Launch()): void
    {
        if (null !== $this->running) {
            throw new DriverException('The application is already started.');
        }
        if (!Process::isPtySupported()) {
            throw new DriverException('The process driver needs pseudo-terminal support, which this system does not have; use the in-process driver.');
        }

        // The configured command and variables come first, and name the workspace as the scenario's do
        $launch = new Launch([...$this->command, ...$launch->arguments], [...$this->env, ...$launch->env], $launch->workspace, $launch->kitty)->resolved();
        $cwd = $this->cwd;
        if (null !== $cwd && \str_contains($cwd, '%workspace%')) {
            $cwd = \str_replace('%workspace%', replace: $launch->workspace ?? throw new \LogicException(\sprintf('"%s" names the workspace, but the scenario has none.', $cwd)), subject: $cwd);
        }
        $this->kitty = $launch->kitty;
        $this->kittyAnswers = 0;
        $this->observer = new TerminalObserver();
        // Draining the pty while it waits keeps an application blocked on its output able to answer the probe
        $this->running = RunningProcess::start(
            $launch->arguments,
            $cwd,
            $launch->env,
            $columns,
            $rows,
            $this->probeTimeout,
            $this->isRunning(...),
            function (): void {
                $this->pump(0.0);
            },
            $this->tempDir ?? \sys_get_temp_dir(),
        );
        $this->screen = new ScreenBuffer($columns, $rows);
    }

    public function stop(): void
    {
        $this->running?->stop();
        $this->running = null;
    }

    public function isStarted(): bool
    {
        return null !== $this->running;
    }

    public function isRunning(): bool
    {
        return $this->running?->process->isRunning() ?? false;
    }

    public function isInLineMode(): bool
    {
        return $this->running()->isInLineMode();
    }

    public function getExitCode(): ?int
    {
        return $this->running?->getExitCode();
    }

    public function send(string $bytes): void
    {
        $this->running()->send($bytes);
    }

    public function resize(int $columns, int $rows): void
    {
        $this->running()->resize($columns, $rows);
        // A resize repaints the whole frame, so a fresh buffer of the new size sees all of it
        $this->screen = new ScreenBuffer($columns, $rows);
    }

    public function pump(float $seconds): bool
    {
        $running = $this->running();
        $deadline = \microtime(true) + $seconds;
        $wrote = false;

        while (true) {
            $output = $running->read();
            if ('' !== $output) {
                $this->getScreen()->write($output);
                $this->observer->observe($output);
                $this->answerKittyQueries($running);
                $wrote = true;
            }
            if (!$running->process->isRunning() || \microtime(true) >= $deadline) {
                return $wrote;
            }
            \usleep(self::POLL_MICROSECONDS);
        }
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
        return WidgetTree::fromXml($this->running()->probe->tree());
    }

    public function focusWidget(int $index): void
    {
        $this->running()->probe->focus($index);
    }

    /**
     * Tells the application the terminal speaks the kitty keyboard protocol, as kitty or WezTerm would, when the scenario asked for it.
     */
    private function answerKittyQueries(RunningProcess $running): void
    {
        for (; $this->kitty && $this->kittyAnswers < $this->observer->getKittyQueryCount(); ++$this->kittyAnswers) {
            $running->send("\x1b[?1u");
        }
    }

    private function running(): RunningProcess
    {
        return $this->running ?? throw new DriverException('The application is not started.');
    }
}
