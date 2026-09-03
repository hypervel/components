<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use DateInterval;
use DateTimeInterface;
use Hypervel\Bus\UniqueJobPayloadContext;
use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Config\Repository as ConfigRepository;
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

class UniqueJobPayloadContextTest extends TestCase
{
    public function testMetadataIsConsumedOnceByTheExactJob(): void
    {
        $registered = new UniqueJobPayloadContextJob('registered');
        $other = new UniqueJobPayloadContextJob('other');

        UniqueJobPayloadContext::register($registered);

        $this->assertNull(UniqueJobPayloadContext::consume($other));
        $this->assertSame([
            'laravel_unique_job_cache_store' => 'unique',
            'laravel_unique_job_key' => 'laravel_unique_job:' . UniqueJobPayloadContextJob::class . ':registered',
        ], UniqueJobPayloadContext::consume($registered));
        $this->assertNull(UniqueJobPayloadContext::consume($registered));
    }

    #[DataProvider('uniqueCacheStores')]
    public function testRegistrationPreservesNullableCacheStoreSelection(string $kind, ?string $expectedStore): void
    {
        $container = new Container;
        $container->instance('config', new ConfigRepository([
            'cache' => ['default' => 'default'],
        ]));
        $container->instance(EventDispatcher::class, new EventsDispatcher($container));
        Container::setInstance($container);

        $queue = new UniqueJobPayloadSyncQueue;
        $queue->setContainer($container);
        $queue->setConnectionName('test');

        $job = match ($kind) {
            'no method' => new UniqueJobPayloadContextDefaultCacheJob($kind),
            'null' => new UniqueJobPayloadContextNullableCacheJob($kind, null),
            'named' => new UniqueJobPayloadContextNullableCacheJob(
                $kind,
                new Repository(new WorkerArrayStore, ['store' => 'unique']),
            ),
            'unnamed' => new UniqueJobPayloadContextNullableCacheJob(
                $kind,
                new Repository(new WorkerArrayStore),
            ),
        };

        $metadata = null;
        SyncQueue::createPayloadUsing(function () use (&$metadata): array {
            $metadata = ContextRepository::getInstance()->allHidden();

            return [];
        });

        UniqueJobPayloadContext::register($job);
        $queue->push($job);

        $this->assertSame([
            'laravel_unique_job_cache_store' => $expectedStore,
            'laravel_unique_job_key' => 'laravel_unique_job:' . get_class($job) . ':' . $kind,
        ], $metadata);

        if ($job instanceof UniqueJobPayloadContextNullableCacheJob) {
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
        $job = new UniqueJobPayloadContextJob('released');
        $reference = WeakReference::create($job);

        UniqueJobPayloadContext::register($job);

        unset($job);

        $this->assertNull($reference->get());
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

        $job = new UniqueJobPayloadContextJob('after-commit');
        UniqueJobPayloadContext::register($job);

        if ($delayed) {
            $queue->later(5, $job);
        } else {
            $queue->push($job);
        }

        $this->assertSame([
            'laravel_unique_job_cache_store' => 'unique',
            'laravel_unique_job_key' => 'laravel_unique_job:' . UniqueJobPayloadContextJob::class . ':after-commit',
        ], $metadata);
        $this->assertNull(UniqueJobPayloadContext::consume($job));
    }

    public static function afterCommitPayloadBuilders(): array
    {
        return [
            'sync push' => [UniqueJobPayloadSyncQueue::class, false],
            'background push' => [UniqueJobPayloadBackgroundQueue::class, false],
            'deferred push' => [UniqueJobPayloadDeferredQueue::class, false],
            'background later' => [UniqueJobPayloadBackgroundQueue::class, true],
            'deferred later' => [UniqueJobPayloadDeferredQueue::class, true],
        ];
    }
}

class UniqueJobPayloadContextJob implements ShouldBeUnique
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

class UniqueJobPayloadContextDefaultCacheJob implements ShouldBeUnique
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

class UniqueJobPayloadContextNullableCacheJob implements ShouldBeUnique
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

class UniqueJobPayloadSyncQueue extends SyncQueue
{
    protected function executePayload(string $payload, ?string $queue = null): int
    {
        return 0;
    }
}

class UniqueJobPayloadBackgroundQueue extends BackgroundQueue
{
    protected function executePayload(string $payload, ?string $queue = null): int
    {
        return 0;
    }

    protected function scheduleTimer(DateInterval|DateTimeInterface|int $delay, string $payload, ?string $queue): int
    {
        return 1;
    }
}

class UniqueJobPayloadDeferredQueue extends DeferredQueue
{
    protected function executePayload(string $payload, ?string $queue = null): int
    {
        return 0;
    }

    protected function scheduleTimer(DateInterval|DateTimeInterface|int $delay, string $payload, ?string $queue): int
    {
        return 1;
    }
}
