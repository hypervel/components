<?php

declare(strict_types=1);

namespace Hypervel\Coordinator;

use Hypervel\Engine\Coroutine;
use Psr\Log\LoggerInterface;
use Throwable;

use function Hypervel\Coroutine\go;

class Timer
{
    public const STOP = 'stop';

    /**
     * Timer IDs mapped to coroutine IDs, or zero until creation publishes the ID.
     *
     * @var array<int, int>
     */
    private array $coroutines = [];

    /**
     * Timer IDs currently blocked in a cancellable coordinator wait.
     *
     * @var array<int, true>
     */
    private array $waiting = [];

    private int $id = 0;

    private static int $count = 0;

    private static int $round = 0;

    public function __construct(private ?LoggerInterface $logger = null)
    {
    }

    /**
     * Execute a callback after a given timeout or when the identifier is resumed.
     */
    public function after(float $timeout, callable $closure, string $identifier = Constants::WORKER_EXIT): int
    {
        $id = ++$this->id;
        $coordinator = CoordinatorManager::until($identifier);
        $this->coroutines[$id] = 0;

        try {
            $coroutineId = go(function () use ($timeout, $closure, $coordinator, $id): void {
                if (! isset($this->coroutines[$id])) {
                    return;
                }

                try {
                    ++Timer::$count;
                    $isClosing = match (true) {
                        $timeout > 0 => $this->waitForCoordinator($id, $coordinator, $timeout), // Run after $timeout seconds.
                        $timeout === 0.0 => $coordinator->isClosing(), // Run immediately.
                        default => $this->waitForCoordinator($id, $coordinator), // Run until $identifier resume.
                    };

                    if (isset($this->coroutines[$id])) {
                        $closure($isClosing);
                    }
                } finally {
                    unset($this->coroutines[$id], $this->waiting[$id]);
                    --Timer::$count;
                }
            });

            if (isset($this->coroutines[$id])) {
                $this->coroutines[$id] = $coroutineId;
            }
        } catch (Throwable $exception) {
            unset($this->coroutines[$id], $this->waiting[$id]);

            throw $exception;
        }

        return $id;
    }

    /**
     * Execute a callback repeatedly at a given interval until stopped or the identifier is resumed.
     */
    public function tick(float $timeout, callable $closure, string $identifier = Constants::WORKER_EXIT): int
    {
        $id = ++$this->id;
        $coordinator = CoordinatorManager::until($identifier);
        $this->coroutines[$id] = 0;

        try {
            $coroutineId = go(function () use ($timeout, $closure, $coordinator, $id): void {
                if (! isset($this->coroutines[$id])) {
                    return;
                }

                $round = 0;

                try {
                    ++Timer::$count;

                    while (isset($this->coroutines[$id])) {
                        $isClosing = $this->waitForCoordinator($id, $coordinator, max($timeout, 0.000001));

                        if (! isset($this->coroutines[$id])) {
                            break;
                        }

                        $result = null;

                        try {
                            $result = $closure($isClosing);
                        } catch (Throwable $exception) {
                            if ($this->logger !== null) {
                                $this->logger->error((string) $exception);
                            } else {
                                error_log((string) $exception);
                            }
                        }

                        if ($result === self::STOP || $isClosing) {
                            break;
                        }

                        ++$round;
                        ++Timer::$round;
                    }
                } finally {
                    unset($this->coroutines[$id], $this->waiting[$id]);
                    Timer::$round -= $round;
                    --Timer::$count;
                }
            });

            if (isset($this->coroutines[$id])) {
                $this->coroutines[$id] = $coroutineId;
            }
        } catch (Throwable $exception) {
            unset($this->coroutines[$id], $this->waiting[$id]);

            throw $exception;
        }

        return $id;
    }

    /**
     * Execute a callback when the identifier is resumed.
     */
    public function until(callable $closure, string $identifier = Constants::WORKER_EXIT): int
    {
        return $this->after(-1, $closure, $identifier);
    }

    /**
     * Clear a registered timer callback by its ID.
     */
    public function clear(int $id): void
    {
        $coroutineId = $this->coroutines[$id] ?? null;
        unset($this->coroutines[$id]);

        if ($coroutineId !== null && $coroutineId > 0 && isset($this->waiting[$id])) {
            // Hyperf only removes the registration, leaving the timer coroutine
            // and everything it captured retained until timeout or worker exit.
            Coroutine::cancelById($coroutineId);
        }
    }

    /**
     * Clear all registered timer callbacks.
     */
    public function clearAll(): void
    {
        foreach (array_keys($this->coroutines) as $id) {
            $this->clear($id);
        }
    }

    /**
     * Get the current timer statistics.
     *
     * @return array{num: int, round: int}
     */
    public static function stats(): array
    {
        return [
            'num' => Timer::$count,
            'round' => Timer::$round,
        ];
    }

    /**
     * Wait for the timer's coordinator while recording cancellable ownership.
     */
    private function waitForCoordinator(int $id, Coordinator $coordinator, float $timeout = -1): bool
    {
        $this->waiting[$id] = true;

        try {
            return $coordinator->yield($timeout);
        } finally {
            unset($this->waiting[$id]);
        }
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        Timer::$count = 0;
        Timer::$round = 0;
    }
}
