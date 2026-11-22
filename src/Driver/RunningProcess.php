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
use Janalis\Tuink\Probe\ProbeServer;
use Janalis\Tuink\Probe\TuiProbe;
use Janalis\Tuink\Util\Warnings;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * An application the process driver started, with what only exists while it runs.
 *
 * @internal
 */
final class RunningProcess
{
    /**
     * Sizes the pty, writes its name down, starts the watchdog and becomes the application.
     *
     * The watchdog is forked twice, so the application does not inherit it as a child that its own wait would block
     * on. While the application's parent is still PHP it sleeps; once PHP is gone it sends SIGTERM, then SIGKILL two
     * seconds later if the pid still runs the same command. Without a ps that names the parent, it stands down.
     */
    private const string LAUNCHER = <<<'SH'
        stty cols "$TUINK_COLUMNS" rows "$TUINK_ROWS" 2>/dev/null
        printf %s "$(tty)" > "$TUINK_TTY_FILE"
        ( (
            parent() { ps -o ppid= -p $$ 2>/dev/null | tr -d ' '; }
            while :; do
                ppid=$(parent)
                [ -n "$ppid" ] || exit 0
                [ "$ppid" = "$PPID" ] || break
                sleep 1
            done
            command=$(ps -o command= -p $$ 2>/dev/null)
            kill -TERM $$ || exit 0
            sleep 2
            [ -n "$command" ] && [ "$(ps -o command= -p $$ 2>/dev/null)" = "$command" ] && kill -KILL $$
        ) </dev/null >/dev/null 2>&1 & )
        exec "$@" 2>&1
        SH;

    private readonly OutputBuffer $output;

    private function __construct(
        public readonly Process $process,
        private readonly InputStream $input,
        private readonly string $ttyFile,
        public readonly ProbeServer $probe,
    ) {
        $this->output = new OutputBuffer();
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     * @param \Closure(): bool       $isRunning How the probe server knows the application still runs
     * @param \Closure(): void       $idle      What the probe server does while it waits for the application
     * @param string                $tempDir   Where the terminal name and the probe socket go
     */
    public static function start(array $command, ?string $cwd, array $env, int $columns, int $rows, float $probeTimeout, \Closure $isRunning, \Closure $idle, string $tempDir): self
    {
        // The pty is not the child's controlling terminal, so ps cannot name it: the shell writes it down for resize()
        $ttyFile = \tempnam($tempDir, prefix: 'tuink-tty-');
        if (false === $ttyFile) {
            throw new DriverException('Cannot create a temporary file for the terminal name.');
        }
        // The application connects back if its entry point installs TuiProbe
        $probe = ProbeServer::listen($probeTimeout, $isRunning, $idle, $tempDir);
        $input = new InputStream();
        // The size is set on the pty before exec, so the application reads it from stty as in a real terminal; Symfony
        // Process gives a pty's stderr a pipe of its own, so it is sent to the pty, where a terminal shows it. The pty
        // is not the application's controlling terminal, so nothing hangs it up when PHP dies without stopping it (a
        // test runner killed on timeout): a watchdog ends it then. $$ stays the application's pid across exec, and the
        // watchdog looks for its new parent rather than for PHP, which stays a zombie until its own parent reaps it.
        $process = new Process(
            ['/bin/sh', '-c', self::LAUNCHER, 'tuink', ...$command],
            $cwd,
            [
                'TERM' => 'xterm-256color',
                'COLUMNS' => "{$columns}",
                'LINES' => "{$rows}",
                'TUINK_COLUMNS' => "{$columns}",
                'TUINK_ROWS' => "{$rows}",
                'TUINK_TTY_FILE' => $ttyFile,
                TuiProbe::ENV => $probe->path,
                ...$env,
            ],
            $input,
            timeout: null,
        );
        $process->setPty(true);
        $running = new self($process, $input, $ttyFile, $probe);

        try {
            $process->start();
        } catch (\Throwable $e) {
            $running->stop();
            throw new DriverException(\sprintf('Cannot start "%s": %s', \implode(' ', $command), $e->getMessage()), previous: $e);
        }

        return $running;
    }

    public function stop(): void
    {
        $this->process->stop();
        Warnings::capture(fn (): bool => \unlink($this->ttyFile));
        $this->probe->close();
    }

    public function send(string $bytes): void
    {
        if (!$this->process->isRunning()) {
            throw new DriverException('Cannot send input: the application is not running.');
        }

        $this->input->write($bytes);
    }

    public function resize(int $columns, int $rows): void
    {
        $pid = $this->process->getPid() ?? throw new DriverException('Cannot resize: the application is not running.');

        [$tty, $warning] = $this->ttyName();
        if (null === $tty) {
            throw new DriverException(\sprintf('Cannot resize the terminal of process %d: its name is unknown (%s).', $pid, $warning ?? 'not written yet'));
        }
        $out = [];
        $code = null;
        \exec(self::stty($tty, \sprintf('cols %d rows %d', $columns, $rows)), $out, $code);
        if (0 !== $code) {
            throw new DriverException(\sprintf('Cannot resize the terminal of process %d: %s', $pid, \implode("\n", $out)));
        }

        // The pty is not the child's controlling terminal, so the kernel does not signal it
        \exec('kill -WINCH '.$pid);
    }

    /**
     * Whether the pty still reads input a line at a time; it does until the application turns canonical mode off. A pty
     * the launcher has not named yet, or one stty cannot read, counts as in the line mode every pty starts in.
     */
    public function isInLineMode(): bool
    {
        [$tty] = $this->ttyName();
        $out = [];
        $code = null;
        \exec(self::stty($tty ?? '', arguments: '-a'), $out, $code);

        return 0 !== $code || 1 === \preg_match('/(?<![-\w])icanon\b/', \implode(' ', $out));
    }

    /**
     * The output written since the last read, holding back an unfinished escape sequence or character for the next.
     */
    public function read(): string
    {
        return $this->output->feed($this->process->getIncrementalOutput());
    }

    public function getExitCode(): ?int
    {
        return $this->process->isRunning() ? null : $this->process->getExitCode();
    }

    /**
     * The name of the pty, which the launcher writes before it runs the command, and the warning reading it raised.
     *
     * @return array{?string, ?string}
     */
    private function ttyName(): array
    {
        [$tty, $warning] = Warnings::capture(fn (): string|false => \file_get_contents($this->ttyFile));

        return [false === $tty || '' === $tty ? null : $tty, $warning];
    }

    /**
     * The stty command that reads or sets the pty, which is not the caller's terminal, with its errors in its output.
     */
    private static function stty(string $tty, string $arguments): string
    {
        return \sprintf('stty %s %s %s 2>&1', 'Darwin' === \PHP_OS_FAMILY ? '-f' : '-F', \escapeshellarg($tty), $arguments);
    }
}
