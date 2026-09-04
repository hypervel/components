<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use DateInterval;
use DateTimeInterface;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Events\Dispatcher as EventsDispatcher;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Queue\BackgroundQueue;
use Hypervel\Queue\DeferredQueue;
use Hypervel\Queue\SyncQueue;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use WeakReference;

class DispatchLockContextTest extends TestCase
{
    public function testMetadataRemainsAvailableUntilTheExactJobIsAccepted(): void
    {
        $cache = new Repository(new WorkerArrayStore, ['store' => 'unique']);
        $registered = new DispatchLockContextJob('registered');
        $other = new DispatchLockContextJob('other');

        $this->assertTrue((new UniqueLock($cache))->acquireForDispatch($registered));

        $this->assertNull(DispatchLockContext::peekPayloadMetadata($other));
        $metadata = DispatchLockContext::peekPayloadMetadata($registered);
        $this->assertNotNull($metadata);
        $this->assertNotSame('', $metadata['laravel_unique_job_lock_owner']);
        $this->assertSame([
            'laravel_unique_job_cache_store' => 'unique',
            'laravel_unique_job_key' => 'laravel_unique_job:' . DispatchLockContextJob::class . ':registered',
            'laravel_unique_job_lock_owner' => $metadata['laravel_unique_job_lock_owner'],
        ], $metadata);
        $this->assertSame($metadata, DispatchLockContext::peekPayloadMetadata($registered));

        DispatchLockContext::accept($registered);

        $this->assertNull(DispatchLockContext::peekPayloadMetadata($registered));
    }

    #[DataProvider('uniqueCacheStores')]
    public function testRegistrationPreservesNullableCacheStoreSelection(string $kind, ?string $expectedStore): void
    {
        $container = new Container;
        $container->instance(EventDispatcher::class, new EventsDispatcher($container));
        Container::setInstance($container);
        $defaultCache = new Repository(new WorkerArrayStore, ['store' => 'default']);

        $queue = new DispatchLockContextSyncQueue;
        $queue->setContainer($container);
        $queue->setConnectionName('test');

        $job = match ($kind) {
            'no method' => new DispatchLockContextDefaultCacheJob($kind),
            'null' => new DispatchLockContextNullableCacheJob($kind, null),
            'named' => new DispatchLockContextNullableCacheJob(
                $kind,
                new Repository(new WorkerArrayStore, ['store' => 'unique']),
            ),
            'unnamed' => new DispatchLockContextNullableCacheJob(
                $kind,
                new Repository(new WorkerArrayStore),
            ),
        };

        $metadata = null;
        SyncQueue::createPayloadUsing(function () use (&$metadata): array {
            $metadata = ContextRepository::getInstance()->allHidden();

            return [];
        });

        $this->assertTrue((new UniqueLock($defaultCache))->acquireForDispatch($job));
        $queue->push($job);

        $this->assertIsArray($metadata);
        $this->assertSame($expectedStore, $metadata['laravel_unique_job_cache_store']);
        $this->assertSame('laravel_unique_job:' . get_class($job) . ':' . $kind, $metadata['laravel_unique_job_key']);
        $this->assertNotSame('', $metadata['laravel_unique_job_lock_owner']);
        $this->assertNull(DispatchLockContext::peekPayloadMetadata($job));

        if ($job instanceof DispatchLockContextNullableCacheJob) {
            $this->assertSame(1, $job->uniqueViaCalls);
        }
    }

    public static function uniqueCacheStores(): array
    {
        return [
            'job without uniqueVia' => ['no method', 'default'],
            'null repository' => ['null', 'default'],
            'named repository' => ['named', 'unique'],
            'unnamed repository' => ['unnamed', null],
        ];
    }

    public function testRegistrationDoesNotRetainTheJob(): void
    {
        $job = new DispatchLockContextJob('released');
        $reference = WeakReference::create($job);

        DispatchLockContext::registerUnique(
            $job,
            new Repository(new WorkerArrayStore, ['store' => 'unique']),
            'unique',
            UniqueLock::getKey($job),
            'owner',
        );

        unset($job);

        $this->assertNull($reference->get());
    }

    public function testStaleDispatchReleaseCannotRemoveNewerOwnersLock(): void
    {
        $cache = new Repository(new WorkerArrayStore, ['store' => 'unique']);
        $lock = new UniqueLock($cache);
        $first = new DispatchLockContextDefaultCacheJob('shared');
        $second = new DispatchLockContextDefaultCacheJob('shared');
        $key = UniqueLock::getKey($first);

        $this->assertTrue($lock->acquireForDispatch($first));
        $firstMetadata = DispatchLockContext::peekPayloadMetadata($first);
        $this->assertNotNull($firstMetadata);
        $this->assertNotSame('', $firstMetadata['laravel_unique_job_lock_owner']);

        UniqueLock::releaseOwned(
            $cache,
            $key,
            $firstMetadata['laravel_unique_job_lock_owner'],
        );

        $this->assertTrue($lock->acquireForDispatch($second));
        $secondMetadata = DispatchLockContext::peekPayloadMetadata($second);
        $this->assertNotNull($secondMetadata);
        $this->assertNotSame('', $secondMetadata['laravel_unique_job_lock_owner']);
        $this->assertNotSame(
            $firstMetadata['laravel_unique_job_lock_owner'],
            $secondMetadata['laravel_unique_job_lock_owner'],
        );

        DispatchLockContext::release($first);

        $secondLock = $cache->restoreLock($key, $secondMetadata['laravel_unique_job_lock_owner']);
        $this->assertTrue($secondLock->isOwnedByCurrentProcess());

        DispatchLockContext::release($second);

        $this->assertFalse($secondLock->isLocked());
    }

    #[DataProvider('afterCommitPayloadBuilders')]
    public function testMetadataSurvivesUntilAfterCommitPayloadCreation(string $queueClass, bool $delayed): void
    {
        $container = new Container;
        $container->instance(Cache::class, m::mock(Cache::class));
        $container->instance(ContainerContract::class, $container);
        $container->instance(EventDispatcher::class, new EventsDispatcher($container));
        Container::setInstance($container);

        $transactionManager = m::mock(DatabaseTransactionsManager::class);
        $transactionManager->shouldReceive('addCallback')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback());
        $transactionManager->shouldReceive('addCallbackForRollback')->once()->andReturnNull();
        $container->instance('db.transactions', $transactionManager);

        $metadata = null;
        SyncQueue::createPayloadUsing(function () use (&$metadata): array {
            $metadata = ContextRepository::getInstance()->allHidden();

            return [];
        });

        /** @var SyncQueue $queue */
        $queue = new $queueClass;
        $queue->setContainer($container);
        $queue->setConnectionName('test');

        $job = new DispatchLockContextJob('after-commit');
        DispatchLockContext::registerUnique(
            $job,
            new Repository(new WorkerArrayStore, ['store' => 'unique']),
            'unique',
            UniqueLock::getKey($job),
            'owner',
        );

        $result = $delayed
            ? $queue->later(5, $job)
            : $queue->push($job);

        $this->assertNull($result);
        $this->assertSame([
            'laravel_unique_job_cache_store' => 'unique',
            'laravel_unique_job_key' => 'laravel_unique_job:' . DispatchLockContextJob::class . ':after-commit',
            'laravel_unique_job_lock_owner' => 'owner',
        ], $metadata);
        $this->assertNull(DispatchLockContext::peekPayloadMetadata($job));
    }

    public static function afterCommitPayloadBuilders(): array
    {
        return [
            'sync push' => [DispatchLockContextSyncQueue::class, false],
            'background push' => [DispatchLockContextBackgroundQueue::class, false],
            'deferred push' => [DispatchLockContextDeferredQueue::class, false],
            'background later' => [DispatchLockContextBackgroundQueue::class, true],
            'deferred later' => [DispatchLockContextDeferredQueue::class, true],
        ];
    }
}

class DispatchLockContextJob implements ShouldBeUnique
{
    public bool $afterCommit = true;

    public function __construct(
        public string $id
    ) {
    }

    public function uniqueId(): string
    {
        return $this->id;
    }

    public function uniqueVia(): Repository
    {
        return new Repository(new WorkerArrayStore, ['store' => 'unique']);
    }
}

class DispatchLockContextDefaultCacheJob implements ShouldBeUnique
{
    public function __construct(
        public string $id
    ) {
    }

    public function uniqueId(): string
    {
        return $this->id;
    }
}

class DispatchLockContextNullableCacheJob implements ShouldBeUnique
{
    public int $uniqueViaCalls = 0;

    public function __construct(
        public string $id,
        private ?Repository $cache,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->id;
    }

    public function uniqueVia(): ?Repository
    {
        ++$this->uniqueViaCalls;

        return $this->cache;
    }
}

class DispatchLockContextSyncQueue extends SyncQueue
{
    protected function executePayload(string $payload, ?string $queue = null): int
    {
        return 0;
    }
}

class DispatchLockContextBackgroundQueue extends BackgroundQueue
{
    protected function executePayload(string $payload, ?string $queue = null): int
    {
        return 0;
    }

    protected function scheduleTimer(DateInterval|DateTimeInterface|int $delay, string $payload, ?string $queue, ?array $snapshot): int
    {
        return 1;
    }
}

class DispatchLockContextDeferredQueue extends DeferredQueue
{
    protected function executePayload(string $payload, ?string $queue = null): int
    {
        return 0;
    }

    protected function scheduleTimer(DateInterval|DateTimeInterface|int $delay, string $payload, ?string $queue, ?array $snapshot): int
    {
        return 1;
    }
}
