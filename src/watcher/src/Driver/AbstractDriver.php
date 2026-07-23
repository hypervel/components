<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use RuntimeException;
use Swoole\Coroutine\System;

abstract class AbstractDriver implements DriverInterface
{
    protected ?Channel $stopSignal = null;

    protected bool $stopping = false;

    public function __construct(protected Option $option)
    {
    }

    /**
     * Determine if the current OS is macOS.
     */
    public function isDarwin(): bool
    {
        return PHP_OS === 'Darwin';
    }

    /**
     * Stop the active watch lifecycle.
     */
    public function stop(): void
    {
        if ($this->stopping) {
            return;
        }

        $this->stopping = true;
        $this->stopSignal?->close();
    }

    /**
     * Run a polling scan until the driver is stopped.
     */
    protected function watchAtInterval(float $seconds, callable $scan): void
    {
        if ($this->stopping) {
            return;
        }

        $stopSignal = $this->stopSignal = new Channel(1);

        try {
            while (true) {
                $signal = $stopSignal->pop($seconds);

                if ($signal !== false || ! $stopSignal->isTimeout()) {
                    return;
                }

                $scan();
            }
        } finally {
            if (! $stopSignal->isClosing()) {
                $stopSignal->close();
            }

            $this->stopSignal = null;
        }
    }

    /**
     * Resolve configured watch paths to absolute targets.
     *
     * @param list<WatchPath> $watchPaths
     * @return list<string>
     */
    protected function resolveTargets(array $watchPaths): array
    {
        return array_map(
            static function (WatchPath $watchPath): string {
                $path = rtrim($watchPath->path, '/');

                return $path === '' || $path === '.'
                    ? base_path()
                    : base_path($path);
            },
            $watchPaths,
        );
    }

    /**
     * Filter targets that currently exist.
     *
     * @param list<string> $targets
     * @return list<string>
     */
    protected function existingTargets(array $targets): array
    {
        return array_values(array_filter($targets, file_exists(...)));
    }

    /**
     * Execute a shell command using Swoole's coroutine-aware exec.
     *
     * Every interpolated argument must be escaped before it reaches this boundary.
     *
     * @return array{code: int, output: string}
     */
    protected function exec(string $command): array
    {
        $result = System::exec($command);

        if ($result === false) {
            throw new RuntimeException("Unable to execute watcher command [{$command}].");
        }

        return $result;
    }

    /**
     * Escape a list of arguments for interpolation into a shell command.
     *
     * @param list<string> $arguments
     */
    protected function shellArguments(array $arguments): string
    {
        return implode(' ', array_map(escapeshellarg(...), $arguments));
    }
}
