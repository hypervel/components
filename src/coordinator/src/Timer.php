<?php

declare(strict_types=1);

namespace Hypervel\Coordinator;

use Psr\Log\LoggerInterface;
use Throwable;

use function Hypervel\Coroutine\go;

class Timer
{
    public const STOP = 'stop';

    private array $closures = [];

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
        $this->closures[$id] = true;

        try {
            go(function () use ($timeout, $closure, $coordinator, $id): void {
                try {
                    ++Timer::$count;
                    $isClosing = match (true) {
                        $timeout > 0 => $coordinator->yield($timeout), // Run after $timeout seconds.
                        $timeout === 0.0 => $coordinator->isClosing(), // Run immediately.
                        default => $coordinator->yield(), // Run until $identifier resume.
                    };

                    if (isset($this->closures[$id])) {
                        $closure($isClosing);
                    }
                } finally {
                    unset($this->closures[$id]);
                    --Timer::$count;
                }
            });
        } catch (Throwable $exception) {
            unset($this->closures[$id]);

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
        $this->closures[$id] = true;

        try {
            go(function () use ($timeout, $closure, $coordinator, $id): void {
                $round = 0;

                try {
                    ++Timer::$count;

                    while (isset($this->closures[$id])) {
                        $isClosing = $coordinator->yield(max($timeout, 0.000001));

                        if (! isset($this->closures[$id])) {
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
                    unset($this->closures[$id]);
                    Timer::$round -= $round;
                    --Timer::$count;
                }
            });
        } catch (Throwable $exception) {
            unset($this->closures[$id]);

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
        unset($this->closures[$id]);
    }

    /**
     * Clear all registered timer callbacks.
     */
    public function clearAll(): void
    {
        $this->closures = [];
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
     * Flush all static state.
     */
    public static function flushState(): void
    {
        Timer::$count = 0;
        Timer::$round = 0;
    }
}
