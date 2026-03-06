<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Coordinator\Timer;
use Hypervel\Watcher\Option;
use RuntimeException;
use Swoole\Coroutine\System;

abstract class AbstractDriver implements DriverInterface
{
    protected Timer $timer;

    protected ?int $timerId = null;

    public function __construct(protected Option $option)
    {
        $this->timer = new Timer();
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Determine if the current OS is macOS.
     */
    public function isDarwin(): bool
    {
        return PHP_OS === 'Darwin';
    }

    /**
     * Stop the file watcher timer.
     */
    public function stop(): void
    {
        if ($this->timerId) {
            $this->timer->clear($this->timerId);
            $this->timerId = null;
        }
    }

    /**
     * Execute a shell command, using Swoole's coroutine-aware exec when available.
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
}
