<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Watcher\Option;
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
     * Execute a shell command, using Swoole's coroutine-aware exec when available.
     *
     * Every interpolated argument must be escaped before it reaches this boundary.
     *
     * @return array{code: int, output: string}
     */
    protected function exec(string $command): array
    {
        if (class_exists(System::class)) {
            return System::exec($command);
        }

        if (function_exists('exec')) {
            \exec($command, $output, $code);
            return ['code' => $code, 'output' => implode(PHP_EOL, $output)];
        }

        throw new RuntimeException('No available function to run command.');
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
