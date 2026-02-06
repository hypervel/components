<?php

declare(strict_types=1);

namespace Hypervel\Coordinator;

use Psr\Log\LoggerInterface;
use Throwable;

use function Hypervel\Coroutine\go;

class Timer
{
    public const string STOP = 'stop';

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
        $this->closures[$id] = true;
        go(function () use ($timeout, $closure, $identifier, $id) {
            try {
                ++Timer::$count;
                $isClosing = match (true) {
                    $timeout > 0 => CoordinatorManager::until($identifier)->yield($timeout), // Run after $timeout seconds.
                    $timeout === 0.0 => CoordinatorManager::until($identifier)->isClosing(), // Run immediately.
                    default => CoordinatorManager::until($identifier)->yield(), // Run until $identifier resume.
                };
                if (isset($this->closures[$id])) {
                    $closure($isClosing);
                }
            } finally {
                unset($this->closures[$id]);
                --Timer::$count;
            }
        });
        return $id;
    }

    /**
     * Execute a callback repeatedly at a given interval until stopped or the identifier is resumed.
     */
    public function tick(float $timeout, callable $closure, string $identifier = Constants::WORKER_EXIT): int
    {
        $id = ++$this->id;
        $this->closures[$id] = true;
        go(function () use ($timeout, $closure, $identifier, $id) {
            try {
                $round = 0;
                ++Timer::$count;
                while (true) {
                    $isClosing = CoordinatorManager::until($identifier)->yield(max($timeout, 0.000001));
                    if (! isset($this->closures[$id])) {
                        break;
                    }

                    $result = null;

                    try {
                        $result = $closure($isClosing);
                    } catch (Throwable $exception) {
                        $this->logger?->error((string) $exception);
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
}
