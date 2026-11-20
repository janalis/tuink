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
        // The size is set on the pty before exec, so the application reads it from stty as in a real terminal
        $process = new Process(
            ['/bin/sh', '-c', 'stty cols "$TUINK_COLUMNS" rows "$TUINK_ROWS" 2>/dev/null; printf %s "$(tty)" > "$TUINK_TTY_FILE"; exec "$@"', 'tuink', ...$command],
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

        $tty = (string) \file_get_contents($this->ttyFile);
        $out = [];
        $code = null;
        $flag = 'Darwin' === \PHP_OS_FAMILY ? '-f' : '-F';
        \exec(\sprintf('stty %s %s cols %d rows %d 2>&1', $flag, \escapeshellarg($tty), $columns, $rows), $out, $code);
        if (0 !== $code) {
            throw new DriverException(\sprintf('Cannot resize the terminal of process %d: %s', $pid, \implode("\n", $out)));
        }

        // The pty is not the child's controlling terminal, so the kernel does not signal it
        \exec('kill -WINCH '.$pid);
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
}
