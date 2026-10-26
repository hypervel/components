<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Closure;
use Hypervel\Bus\DebounceLock;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\ArrayLock;
use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Waiter;
use Hypervel\Engine\Channel;
use Hypervel\Events\Dispatcher as EventsDispatcher;
use Hypervel\Queue\BackgroundQueue;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class DispatchLockReleaseContextTest extends TestCase
{
    #[DataProvider('releasePaths')]
    public function testReleaseRestoresAcquisitionContextAndPreservesOtherContexts(string $kind, bool $snapshot): void
    {
        $this->registerContextCapture();
        $order = [];
        DispatchLockContext::captureReleaseContextUsing(static function () use (&$order): Closure {
            return static function (Closure $release) use (&$order): void {
                $order[] = CoroutineContext::get(ReleaseContextCacheStore::CONTEXT_KEY);
                $release();
            };
        });
        $cache = new Repository(new ReleaseContextCacheStore);
        $lock = $kind === 'unique' ? new UniqueLock($cache) : new DebounceLock($cache);
        $job = new ReleaseContextJob;

        CoroutineContext::set(ReleaseContextCacheStore::CONTEXT_KEY, 'first:');
        $lock->acquireForDispatch($job);
        $key = $lock::getKey($job);
        $captured = DispatchLockContext::snapshot($job);

        if ($snapshot) {
            DispatchLockContext::accept($job);
        }

        CoroutineContext::set(ReleaseContextCacheStore::CONTEXT_KEY, 'second:');
        $other = new ReleaseContextJob;
        $lock->acquireForDispatch($other);

        if ($snapshot) {
            DispatchLockContext::releaseSnapshot($captured);
        } else {
            DispatchLockContext::release($job);
        }

        $this->assertSame(['first:'], $order);
        $this->assertSame('second:', CoroutineContext::get(ReleaseContextCacheStore::CONTEXT_KEY));
        $this->assertTrue($kind === 'unique' ? $cache->lock($key)->isLocked() : $cache->has($key));

        CoroutineContext::set(ReleaseContextCacheStore::CONTEXT_KEY, 'first:');
        $this->assertFalse($kind === 'unique' ? $cache->lock($key)->isLocked() : $cache->has($key));
        DispatchLockContext::release($other);
        $this->assertSame('first:', CoroutineContext::get(ReleaseContextCacheStore::CONTEXT_KEY));
    }

    /**
     * Provide each lock's live-record and accepted-snapshot release paths.
     */
    public static function releasePaths(): array
    {
        return [
            'unique live record' => ['unique', false],
            'unique accepted snapshot' => ['unique', true],
            'debounce live record' => ['debounce', false],
            'debounce accepted snapshot' => ['debounce', true],
        ];
    }

    public function testCaptureFailureLeavesNeitherLockNorTokenAcquired(): void
    {
        $exception = new RuntimeException('Cannot capture context.');
        DispatchLockContext::captureReleaseContextUsing(static fn () => throw $exception);
        $cache = new Repository(new WorkerArrayStore);
        $job = new ReleaseContextJob;

        foreach ([new UniqueLock($cache), new DebounceLock($cache)] as $lock) {
            try {
                $lock->acquireForDispatch($job);
                $this->fail('Context capture should have failed before acquisition.');
            } catch (RuntimeException $caught) {
                $this->assertSame($exception, $caught);
            }
        }

        $this->assertFalse($cache->lock(UniqueLock::getKey($job))->isLocked());
        $this->assertFalse($cache->has(DebounceLock::getKey($job)));
        $this->assertFalse(DispatchLockContext::has($job));
    }

    public function testReleaseFailureRestoresTheCallingContext(): void
    {
        $this->registerContextCapture();
        $exception = new RuntimeException('Cleanup failed.');
        DispatchLockContext::captureReleaseContextUsing(static fn () => static fn () => throw $exception);
        CoroutineContext::set(ReleaseContextCacheStore::CONTEXT_KEY, 'first:');
        $job = new ReleaseContextJob;
        (new UniqueLock(new Repository(new ReleaseContextCacheStore)))->acquireForDispatch($job);
        CoroutineContext::set(ReleaseContextCacheStore::CONTEXT_KEY, 'second:');

        try {
            DispatchLockContext::release($job);
            $this->fail('The release wrapper should have failed.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame('second:', CoroutineContext::get(ReleaseContextCacheStore::CONTEXT_KEY));
        $this->assertFalse(DispatchLockContext::has($job));
    }

    public function testClosingTimerReleasesTheLockOutsideItsAcquiringCoroutine(): void
    {
        $this->registerContextCapture();
        $released = new Channel(1);
        DispatchLockContext::captureReleaseContextUsing(static function () use ($released): Closure {
            return static function (Closure $release) use ($released): void {
                $release();
                $released->push(true);
            };
        });

        $container = new Container;
        Container::setInstance($container);
        $container->instance(Dispatcher::class, new EventsDispatcher($container));
        $timer = new Timer;
        $queue = new BackgroundQueue(timer: $timer);
        $queue->setContainer($container);
        $queue->setConnectionName('background');
        $cache = new Repository(new ReleaseContextCacheStore);
        $job = new ReleaseContextJob;

        try {
            (new Waiter)->wait(function () use ($queue, $cache, $job): void {
                CoroutineContext::set(ReleaseContextCacheStore::CONTEXT_KEY, 'first:');
                $this->assertTrue((new UniqueLock($cache))->acquireForDispatch($job));
                $queue->later(60, $job);
                $this->assertFalse(DispatchLockContext::has($job));
            });

            CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
            $this->assertTrue($released->pop(2));
            CoroutineContext::set(ReleaseContextCacheStore::CONTEXT_KEY, 'first:');
            $this->assertFalse($cache->lock(UniqueLock::getKey($job))->isLocked());
        } finally {
            $timer->clearAll();
            $released->close();
        }
    }

    public function testNullCaptureAndClearingRegistrationsDoNotWrapRelease(): void
    {
        DispatchLockContext::captureReleaseContextUsing(static fn () => null);
        $this->assertNull(DispatchLockContext::captureReleaseContext());
        DispatchLockContext::captureReleaseContextUsing(static fn () => throw new RuntimeException);
        DispatchLockContext::captureReleaseContextUsing(null);
        $this->assertNull(DispatchLockContext::captureReleaseContext());
    }

    /**
     * Capture the cache prefix without retaining an entire coroutine context.
     */
    protected function registerContextCapture(): void
    {
        DispatchLockContext::captureReleaseContextUsing(static function (): Closure {
            $prefix = CoroutineContext::get(ReleaseContextCacheStore::CONTEXT_KEY);

            return static function (Closure $release) use ($prefix): void {
                $previous = CoroutineContext::get(ReleaseContextCacheStore::CONTEXT_KEY);
                CoroutineContext::set(ReleaseContextCacheStore::CONTEXT_KEY, $prefix);

                try {
                    $release();
                } finally {
                    CoroutineContext::set(ReleaseContextCacheStore::CONTEXT_KEY, $previous);
                }
            };
        });
    }
}

class ReleaseContextJob implements ShouldBeUnique
{
    public int $debounceFor = 10;
}

class ReleaseContextCacheStore extends WorkerArrayStore
{
    public const string CONTEXT_KEY = '__tests.dispatch_lock_prefix';

    /**
     * Resolve the namespace from the current execution.
     */
    public function getPrefix(): string
    {
        return CoroutineContext::get(self::CONTEXT_KEY, '');
    }

    /**
     * Create a lock in the current namespace.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): ArrayLock
    {
        return parent::lock($this->getPrefix() . $name, $seconds, $owner);
    }

    /**
     * Read a value from the current namespace.
     */
    public function get(string $key): mixed
    {
        return parent::get($this->getPrefix() . $key);
    }

    /**
     * Store a value in the current namespace.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        return parent::put($this->getPrefix() . $key, $value, $seconds);
    }

    /**
     * Remove a value from the current namespace.
     */
    public function forget(string $key): bool
    {
        return parent::forget($this->getPrefix() . $key);
    }
}
