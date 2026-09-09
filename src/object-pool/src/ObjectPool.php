<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Closure;
use Hypervel\Contracts\ObjectPool\ObjectPool as ObjectPoolContract;
use Hypervel\Coroutine\PoolChannel;
use Hypervel\ObjectPool\Exceptions\PoolClosedException;
use Hypervel\ObjectPool\Exceptions\PoolExhaustedException;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * @template T of object
 */
abstract class ObjectPool implements ObjectPoolContract
{
    /** @var PoolChannel<T> */
    protected PoolChannel $channel;

    /** @var array<int, true> */
    protected array $managed = [];

    /** @var array<int, true> */
    protected array $borrowed = [];

    /** @var array<int, int> */
    protected array $creationTimes = [];

    /** @var array<int, int> */
    protected array $releaseTimes = [];

    protected int $lastUsedAt;

    protected bool $closed = false;

    protected int $acquiringCount = 0;

    protected int $creatingCount = 0;

    protected ?Closure $destroyCallback;

    /**
     * Create an object pool.
     */
    public function __construct(
        protected PoolOptions $options,
        ?Closure $destroyCallback = null,
    ) {
        $this->destroyCallback = $destroyCallback;
        $this->channel = new PoolChannel($options->maxObjects);
        $this->lastUsedAt = hrtime(true);
    }

    /**
     * Borrow an object from the pool.
     *
     * @return T
     */
    public function borrow(): object
    {
        if ($this->closed) {
            throw new PoolClosedException('Cannot borrow from a closed pool.');
        }

        $this->lastUsedAt = hrtime(true);
        $deadline = $this->deadline($this->options->waitTimeout);
        ++$this->acquiringCount;

        try {
            $object = $this->getObject($deadline);

            $this->borrowed[spl_object_id($object)] = true;

            return $object;
        } finally {
            --$this->acquiringCount;
        }
    }

    /**
     * Release an object back to the pool.
     *
     * @param T $object
     */
    public function release(object $object): void
    {
        $id = $this->ensureBorrowed($object);
        unset($this->borrowed[$id]);

        if ($this->closed) {
            $this->destroyObject($object);

            return;
        }

        $now = hrtime(true);
        $this->lastUsedAt = $now;
        $this->releaseTimes[$id] = $now;

        $this->channel->push($object);
    }

    /**
     * Destroy a checked-out object instead of returning it to the pool.
     */
    public function discard(object $object): void
    {
        $id = $this->ensureBorrowed($object);
        unset($this->borrowed[$id]);

        $this->destroyObject($object);
    }

    /**
     * Destroy idle objects that exceed the maximum lifetime.
     */
    public function sweepExpired(): void
    {
        if ($this->options->maxLifetime === null) {
            return;
        }

        $remaining = $this->channel->length();

        while ($remaining-- > 0 && ($object = $this->channel->pop()) !== false) {
            if ($this->exceedsMaxLifetime($object)) {
                $this->destroyObject($object);
            } else {
                $this->requeue($object);
            }
        }
    }

    /**
     * Destroy idle objects past the maximum idle time down to the retention floor.
     */
    public function trimIdle(): void
    {
        if ($this->options->maxIdleTime === null) {
            return;
        }

        $remaining = $this->channel->length();
        $threshold = $this->nanoseconds($this->options->maxIdleTime);
        $now = hrtime(true);

        while ($remaining-- > 0
            && count($this->managed) > $this->options->minRetainedObjects
            && ($object = $this->channel->pop()) !== false
        ) {
            $id = spl_object_id($object);
            $idleSince = $this->releaseTimes[$id] ?? $this->creationTimes[$id];

            if (($now - $idleSince) > $threshold) {
                $this->destroyObject($object);
            } else {
                $this->requeue($object);
            }
        }
    }

    /**
     * Close the pool and destroy all idle objects.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->channel->close();
        $cancellation = null;

        while (($object = $this->channel->pop()) !== false) {
            try {
                $this->destroyObject($object);
            } catch (CanceledException $exception) {
                $cancellation ??= $exception;
            }
        }

        if ($cancellation !== null) {
            throw $cancellation;
        }
    }

    /**
     * Determine if the pool is closed.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Determine if the entire pool has exceeded its idle timeout.
     */
    public function isIdleExpired(): bool
    {
        return $this->options->poolIdleTimeout !== null
            && $this->acquiringCount === 0
            && $this->getBorrowedCount() === 0
            && (hrtime(true) - $this->lastUsedAt) > $this->nanoseconds($this->options->poolIdleTimeout);
    }

    /**
     * Return the number of objects currently checked out.
     */
    public function getBorrowedCount(): int
    {
        return count($this->borrowed);
    }

    /**
     * Return the current number of objects managed by the pool.
     */
    public function getManagedCount(): int
    {
        return count($this->managed);
    }

    /**
     * Return the number of objects currently available in the pool.
     */
    public function getIdleCount(): int
    {
        return $this->channel->length();
    }

    /**
     * Return the number of coroutines waiting for an object.
     */
    public function getWaitingCount(): int
    {
        return $this->channel->waiters();
    }

    /**
     * Get the normalized pool options.
     */
    public function getOptions(): PoolOptions
    {
        return $this->options;
    }

    /**
     * Return statistics about the pool's current state.
     *
     * @return array{managed: int, borrowed: int, idle: int, waiting: int, closed: bool}
     */
    public function getStats(): array
    {
        return [
            'managed' => count($this->managed),
            'borrowed' => count($this->borrowed),
            'idle' => $this->getIdleCount(),
            'waiting' => $this->getWaitingCount(),
            'closed' => $this->closed,
        ];
    }

    /**
     * Create a new object for the pool.
     *
     * The factory may yield and allow another coroutine to close the pool.
     *
     * @return T
     * @phpstan-impure
     */
    abstract protected function createObject(): object;

    /**
     * Ensure an object is currently checked out from this pool.
     */
    protected function ensureBorrowed(object $object): int
    {
        $id = spl_object_id($object);

        if (! isset($this->managed[$id])) {
            throw new RuntimeException('Cannot release or discard an object this pool does not manage.');
        }

        if (! isset($this->borrowed[$id])) {
            throw new RuntimeException('Cannot release or discard an object that is not checked out (double release?).');
        }

        return $id;
    }

    /**
     * Return an object to the idle channel without recording activity, or destroy it when the pool has closed.
     *
     * @param T $object
     */
    protected function requeue(object $object): void
    {
        // Maintenance may resume while a concurrent close is still draining the channel.
        if (! $this->channel->push($object)) {
            $this->destroyObject($object);
        }
    }

    /**
     * Destroy an object through the pool's single cleanup path.
     */
    protected function destroyObject(object $object): void
    {
        $id = spl_object_id($object);

        if (! isset($this->managed[$id])) {
            throw new RuntimeException('Cannot destroy an object this pool does not manage.');
        }

        try {
            if ($this->destroyCallback !== null) {
                ($this->destroyCallback)($object);
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            PoolErrorReporter::report($exception);
        } finally {
            unset(
                $this->managed[$id],
                $this->borrowed[$id],
                $this->creationTimes[$id],
                $this->releaseTimes[$id]
            );
            $this->channel->signal();
        }
    }

    /**
     * Determine if an object has exceeded its maximum lifetime.
     */
    protected function exceedsMaxLifetime(object $object): bool
    {
        if ($this->options->maxLifetime === null) {
            return false;
        }

        $createdAt = $this->creationTimes[spl_object_id($object)];

        return (hrtime(true) - $createdAt) >= $this->nanoseconds($this->options->maxLifetime);
    }

    /**
     * Get or create an object before the checkout deadline.
     *
     * @return T
     */
    private function getObject(int $deadline): object
    {
        $timedOut = false;

        while (true) {
            if ($this->closed) {
                throw new PoolClosedException('Cannot borrow from a closed pool.');
            }

            if (($object = $this->channel->pop()) !== false) {
                if ($this->exceedsMaxLifetime($object)) {
                    $this->destroyObject($object);

                    continue;
                }

                return $object;
            }

            if (count($this->managed) + $this->creatingCount < $this->options->maxObjects) {
                ++$this->creatingCount;

                try {
                    $object = $this->createObject();
                } catch (Throwable $exception) {
                    --$this->creatingCount;
                    $this->channel->signal();

                    throw $exception;
                }

                --$this->creatingCount;
                $id = spl_object_id($object);

                if (isset($this->managed[$id])) {
                    $this->channel->signal();

                    throw new RuntimeException(
                        'The pool factory returned an object this pool already manages '
                        . '(a container-resolved auto-singleton?). Factories must construct fresh instances.'
                    );
                }

                $this->managed[$id] = true;
                $this->creationTimes[$id] = hrtime(true);

                if ($this->closed) {
                    $this->destroyObject($object);

                    throw new PoolClosedException('Cannot borrow from a closed pool.');
                }

                return $object;
            }

            if ($timedOut) {
                throw new PoolExhaustedException('Object pool exhausted. Cannot create new object before wait_timeout.');
            }

            $timedOut = ! $this->waitForStateChange($deadline);
        }
    }

    /**
     * Wait for capacity-relevant pool state to change before the deadline.
     */
    private function waitForStateChange(int $deadline): bool
    {
        $remaining = $deadline - hrtime(true);

        if ($remaining <= 0) {
            return false;
        }

        return $this->channel->wait($remaining / 1e9);
    }

    /**
     * Convert seconds to nanoseconds without overflowing integer arithmetic.
     */
    protected function nanoseconds(float $seconds): int
    {
        return $seconds >= PHP_INT_MAX / 1e9
            ? PHP_INT_MAX
            : (int) ($seconds * 1e9);
    }

    /**
     * Build a monotonic deadline without overflowing at long durations or uptimes.
     */
    protected function deadline(float $seconds): int
    {
        $now = hrtime(true);
        $duration = $this->nanoseconds($seconds);

        return $duration > PHP_INT_MAX - $now
            ? PHP_INT_MAX
            : $now + $duration;
    }
}
