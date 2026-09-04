<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\DebounceLock;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Engine\Channel;
use Hypervel\Queue\Attributes\DebounceFor;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class BusDebounceLockTest extends TestCase
{
    public function testCurrentOwnerMayBeRetrievedWithOneCacheRead(): void
    {
        $cache = new CacheRepository(new ConcurrentDebounceStore);
        $lock = new DebounceLock($cache);
        $job = new BusDebounceLockJob('entity-1');

        $cache->put(DebounceLock::getKey($job), 'owner-token', 300);

        $this->assertSame('owner-token', $lock->getCurrentOwner($job));
    }

    public function testConcurrentFirstDispatchesDoNotOverwriteTheMaxWaitAnchor(): void
    {
        $store = new ConcurrentDebounceStore;
        $lock = new DebounceLock(new CacheRepository($store));

        $results = parallel([
            fn (): array => $lock->acquire(new BusDebounceLockJob('entity-1')),
            fn (): array => $lock->acquire(new BusDebounceLockJob('entity-1')),
        ]);

        $this->assertFalse($results[0]['maxWaitExceeded']);
        $this->assertFalse($results[1]['maxWaitExceeded']);
        $this->assertSame(1, $store->successfulTimestampAdds);
    }

    public function testReleaseProbeFailureDoesNotRemoveEitherDebounceRecord(): void
    {
        $failure = new RuntimeException('Owner probe failed.');
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('get')->once()->with('debounce-key')->andThrow($failure);
        $cache->shouldReceive('forget')->never();

        try {
            DebounceLock::releaseOwned($cache, 'debounce-key', 'owner');
            $this->fail('Expected the owner probe failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testAcquisitionFailureRemainsPrimaryWhenCleanupProbeFails(): void
    {
        $job = new BusDebounceLockJob('entity-1');
        $key = DebounceLock::getKey($job);
        $failure = new RuntimeException('Maximum-wait read failed.');
        $cleanupFailure = new RuntimeException('Cleanup owner probe failed.');
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('put')->once()->with($key, m::type('string'), 300)->andReturnTrue();
        $cache->shouldReceive('get')->once()->with($key . ':first_dispatched_at')->andThrow($failure);
        $cache->shouldReceive('get')->once()->with($key)->andThrow($cleanupFailure);
        $cache->shouldReceive('forget')->never();

        try {
            (new DebounceLock($cache))->acquire($job);
            $this->fail('Expected the maximum-wait read failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }
}

#[DebounceFor(30, maxWait: 60)]
class BusDebounceLockJob
{
    public function __construct(public string $entityId)
    {
    }

    public function debounceId(): string
    {
        return $this->entityId;
    }
}

class ConcurrentDebounceStore extends WorkerArrayStore
{
    public int $successfulTimestampAdds = 0;

    private int $timestampReads = 0;

    private Channel $releaseFirstRead;

    public function __construct()
    {
        parent::__construct();

        $this->releaseFirstRead = new Channel(1);
    }

    public function get(string $key): mixed
    {
        $value = parent::get($key);

        if ($value === null && str_ends_with($key, ':first_dispatched_at')) {
            if (++$this->timestampReads === 1) {
                $this->releaseFirstRead->pop();
            } else {
                $this->releaseFirstRead->push(true);
            }
        }

        return $value;
    }

    public function add(string $key, mixed $value, int $seconds): bool
    {
        if (parent::get($key) !== null) {
            return false;
        }

        parent::put($key, $value, $seconds);

        if (str_ends_with($key, ':first_dispatched_at')) {
            ++$this->successfulTimestampAdds;
        }

        return true;
    }
}
