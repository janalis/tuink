<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink;

use Janalis\Tuink\Driver\DriverInterface;
use Janalis\Tuink\Driver\InProcessDriver;
use Janalis\Tuink\Driver\ProcessDriver;

/**
 * Hands out the session of the current scenario, on the driver it asks for.
 */
final class Tuink
{
    /** Seconds of silence after which an in-process application counts as settled. */
    public const IN_PROCESS_QUIET_PERIOD = 0.02;

    /** Seconds of silence after which a process counts as settled; a pty adds latency. */
    public const PROCESS_QUIET_PERIOD = 0.05;

    private string $driverName;
    private ?Session $session = null;

    /**
     * @param array<string, \Closure(): DriverInterface> $drivers
     * @param array<string, float>                       $quietPeriods Per driver name
     */
    public function __construct(
        private readonly array $drivers,
        private readonly string $defaultDriver,
        private readonly int $columns = 80,
        private readonly int $rows = 24,
        private readonly float $timeout = 2.0,
        private readonly array $quietPeriods = [],
        private readonly ?string $snapshotDir = null,
    ) {
        if (!\array_key_exists($defaultDriver, $drivers)) {
            throw new \InvalidArgumentException(\sprintf('The default driver "%s" is not configured; configured drivers: "%s".', $defaultDriver, \implode('", "', \array_keys($drivers))));
        }
        $this->driverName = $defaultDriver;
    }

    /**
     * Builds the manager from the extension configuration.
     *
     * @param array{
     *     default_driver: string,
     *     columns: int,
     *     rows: int,
     *     timeout: float,
     *     quiet_period: ?float,
     *     snapshots: ?string,
     *     in_process?: array{factory: string},
     *     process?: array{command: list<string>, cwd: ?string, env: array<string, string>, probe_timeout?: float},
     * } $config
     */
    public static function fromConfig(array $config, string $basePath): self
    {
        $resolve = static fn (string $path): string => \str_starts_with($path, '/') || \preg_match('~^[A-Za-z]:[\\\\/]~', $path) ? $path : $basePath.'/'.$path;

        $drivers = [];
        $quiet = [];
        if (\array_key_exists('in_process', $config)) {
            $path = $resolve($config['in_process']['factory']);
            $factory = null;
            // The file is required once: a factory file that declares functions or classes cannot run twice
            $drivers['in_process'] = static function () use ($path, &$factory): DriverInterface {
                $factory ??= InProcessDriver::loadFactory($path);

                return new InProcessDriver($factory);
            };
            $quiet['in_process'] = $config['quiet_period'] ?? self::IN_PROCESS_QUIET_PERIOD;
        }
        if (\array_key_exists('process', $config)) {
            $process = $config['process'];
            $cwd = $resolve($process['cwd'] ?? '.');
            $drivers['process'] = static fn (): DriverInterface => new ProcessDriver($process['command'], $cwd, $process['env'], $process['probe_timeout'] ?? 2.0);
            $quiet['process'] = $config['quiet_period'] ?? self::PROCESS_QUIET_PERIOD;
        }

        return new self(
            $drivers,
            $config['default_driver'],
            $config['columns'],
            $config['rows'],
            $config['timeout'],
            $quiet,
            null === $config['snapshots'] ? null : $resolve($config['snapshots']),
        );
    }

    public function hasDriver(string $name): bool
    {
        return \array_key_exists($name, $this->drivers);
    }

    /**
     * @return list<string>
     */
    public function getDriverNames(): array
    {
        return \array_keys($this->drivers);
    }

    public function getDriverName(): string
    {
        return $this->driverName;
    }

    /**
     * Switches the driver; the running session, if any, is stopped.
     */
    public function useDriver(string $name): void
    {
        if (!\array_key_exists($name, $this->drivers)) {
            throw new \InvalidArgumentException(\sprintf('The driver "%s" is not configured; configured drivers: "%s".', $name, \implode('", "', \array_keys($this->drivers))));
        }
        if ($name !== $this->driverName) {
            $this->reset();
            $this->driverName = $name;
        }
    }

    public function getSession(): Session
    {
        return $this->session ??= new Session(
            $this->drivers[$this->driverName](),
            $this->columns,
            $this->rows,
            $this->timeout,
            $this->quietPeriods[$this->driverName] ?? self::PROCESS_QUIET_PERIOD,
            $this->snapshotDir,
        );
    }

    /**
     * Stops the session; the next getSession() starts from scratch.
     */
    public function reset(): void
    {
        $this->session?->stop();
        $this->session = null;
    }

    /**
     * Stops the session and goes back to the default driver, between scenarios.
     */
    public function restoreDefaults(): void
    {
        $this->reset();
        $this->driverName = $this->defaultDriver;
    }
}
