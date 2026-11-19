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
use Janalis\Tuink\Exception\UnsupportedDriverException;
use Janalis\Tuink\Probe\ProbeServer;
use Janalis\Tuink\Probe\TuiProbe;
use Janalis\Tuink\Selector\WidgetTree;
use Symfony\Component\Process\InputStream;
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
    private ?Process $process = null;
    private ?InputStream $input = null;
    private ?ScreenBuffer $screen = null;
    private OutputBuffer $output;
    private TerminalObserver $observer;
    private ?string $ttyFile = null;
    private ?ProbeServer $probe = null;
    private bool $probeMissing = false;

    /**
     * @param list<string>          $command      The command line, as an argument list
     * @param array<string, string> $env          Extra environment variables
     * @param float                 $probeTimeout Seconds to wait for the application's TuiProbe to connect and answer
     */
    public function __construct(
        private readonly array $command,
        private readonly ?string $cwd = null,
        private readonly array $env = [],
        private readonly float $probeTimeout = 2.0,
    ) {
        if ([] === $command) {
            throw new DriverException('The process driver needs a command.');
        }
        $this->output = new OutputBuffer();
        $this->observer = new TerminalObserver();
    }

    public function getName(): string
    {
        return 'process';
    }

    public function start(int $columns, int $rows): void
    {
        if (null !== $this->process) {
            throw new DriverException('The application is already started.');
        }
        if (!Process::isPtySupported()) {
            throw new DriverException('The process driver needs pseudo-terminal support, which this system does not have; use the in-process driver.');
        }

        // The size is set on the pty before exec, so the application reads it from stty like in a real terminal;
        // the pty is not the child's controlling terminal, so ps cannot name it: the shell writes it down for resize()
        $ttyFile = \tempnam(\sys_get_temp_dir(), prefix: 'tuink-tty-');
        $this->ttyFile = false === $ttyFile ? null : $ttyFile;
        // The application connects back if its entry point installs TuiProbe
        $this->probe = ProbeServer::listen();
        $this->probeMissing = false;
        $process = new Process(
            ['/bin/sh', '-c', 'stty cols "$TUINK_COLUMNS" rows "$TUINK_ROWS" 2>/dev/null; [ -n "$TUINK_TTY_FILE" ] && tty > "$TUINK_TTY_FILE"; exec "$@"', 'tuink', ...$this->command],
            $this->cwd,
            [
                'TERM' => 'xterm-256color',
                'COLUMNS' => (string) $columns,
                'LINES' => (string) $rows,
                'TUINK_COLUMNS' => (string) $columns,
                'TUINK_ROWS' => (string) $rows,
                'TUINK_TTY_FILE' => $this->ttyFile ?? '',
                TuiProbe::ENV => $this->probe->path,
                ...$this->env,
            ],
        );
        $process->setPty(true);
        $process->setTimeout(null);
        $this->input = new InputStream();
        $process->setInput($this->input);
        $this->screen = new ScreenBuffer($columns, $rows);
        $this->output = new OutputBuffer();
        $this->observer = new TerminalObserver();

        try {
            $process->start();
        } catch (\Throwable $e) {
            $this->probe->close();
            $this->probe = null;
            throw new DriverException(\sprintf('Cannot start "%s": %s', \implode(' ', $this->command), $e->getMessage()), 0, $e);
        }
        $this->process = $process;
    }

    public function stop(): void
    {
        $this->input?->close();
        if (null !== $this->process && $this->process->isRunning()) {
            $this->process->stop(1.0);
        }
        $this->process = null;
        $this->input = null;
        if (null !== $this->ttyFile) {
            if (\is_file($this->ttyFile)) {
                \unlink($this->ttyFile);
            }
            $this->ttyFile = null;
        }
        $this->probe?->close();
        $this->probe = null;
    }

    public function isStarted(): bool
    {
        return null !== $this->process;
    }

    public function isRunning(): bool
    {
        return null !== $this->process && $this->process->isRunning();
    }

    public function getExitCode(): ?int
    {
        if (null === $this->process || $this->process->isRunning()) {
            return null;
        }

        return $this->process->getExitCode();
    }

    public function send(string $bytes): void
    {
        if (!$this->isRunning()) {
            throw new DriverException('Cannot send input: the application is not running.');
        }

        $this->input?->write($bytes);
    }

    public function resize(int $columns, int $rows): void
    {
        $process = $this->process();
        $pid = $process->getPid() ?? throw new DriverException('Cannot resize: the application is not running.');

        $tty = null !== $this->ttyFile && \is_file($this->ttyFile) ? \trim((string) \file_get_contents($this->ttyFile)) : '';
        if (!\str_starts_with($tty, '/dev/')) {
            throw new DriverException(\sprintf('Cannot resize: the terminal of process %d is unknown.', $pid));
        }

        $this->pump(0.0);
        $flag = 'Darwin' === \PHP_OS_FAMILY ? '-f' : '-F';
        $out = [];
        $code = 0;
        \exec(\sprintf('stty %s %s cols %d rows %d 2>&1', $flag, \escapeshellarg($tty), $columns, $rows), $out, $code);
        if (0 !== $code) {
            throw new DriverException(\sprintf('Cannot resize the terminal of process %d: %s', $pid, \implode("\n", $out)));
        }

        // A resize repaints the whole frame, so a fresh buffer of the new size sees all of it
        $pending = $this->output->flush();
        $this->getScreen()->write($pending);
        $this->observer->observe($pending);
        $this->screen = new ScreenBuffer($columns, $rows);
        // The pty is not the child's controlling terminal, so the kernel does not signal it
        if (\function_exists('posix_kill') && \defined('SIGWINCH')) {
            \posix_kill($pid, \SIGWINCH);

            return;
        }
        \exec('kill -WINCH '.$pid);
    }

    public function pump(float $seconds): bool
    {
        $process = $this->process();
        $deadline = \microtime(true) + \max(0.0, $seconds);
        $wrote = false;

        do {
            $output = $this->output->feed($process->getIncrementalOutput().$process->getIncrementalErrorOutput());
            if (!$process->isRunning()) {
                $output .= $this->output->feed($process->getIncrementalOutput().$process->getIncrementalErrorOutput()).$this->output->flush();
            }
            if ('' !== $output) {
                $this->getScreen()->write($output);
                $this->observer->observe($output);
                $wrote = true;
            }
            if (!$process->isRunning()) {
                break;
            }
            $left = $deadline - \microtime(true);
            if ($left > 0) {
                \usleep(\max(0, (int) (\min($left, 0.005) * 1e6)));
            }
        } while (\microtime(true) < $deadline);

        return $wrote;
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
        return WidgetTree::fromXml(self::xml($this->probeRequest(['op' => 'tree'])));
    }

    public function focusWidget(int $index): void
    {
        $this->probeRequest(['op' => 'focus', 'index' => $index]);
    }

    /**
     * The output the application wrote to its terminal so far, for debugging.
     */
    public function getOutput(): string
    {
        return $this->process()->getOutput();
    }

    /**
     * @param array<array-key, mixed> $response
     */
    private static function xml(array $response): string
    {
        if (!\is_string($response['xml'] ?? null)) {
            throw new DriverException('The tuink probe sent no widget tree.');
        }

        return $response['xml'];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<array-key, mixed>
     */
    private function probeRequest(array $request): array
    {
        $probe = $this->probe ?? throw new DriverException('The application is not started.');
        if ($this->probeMissing) {
            throw new UnsupportedDriverException(\sprintf(
                'The application did not connect to the tuink probe. CSS selectors on the process driver need it to call %s::install($tui) before run(); or tag the scenario @in_process.',
                TuiProbe::class,
            ));
        }

        try {
            return $probe->request($request, $this->probeTimeout, $this->isRunning(...), function (): void {
                $this->pump(0.0);
            });
        } catch (UnsupportedDriverException $e) {
            // Not connected by now, it never will: later steps fail at once
            $this->probeMissing = true;
            throw $e;
        }
    }

    private function process(): Process
    {
        return $this->process ?? throw new DriverException('The application is not started.');
    }
}
