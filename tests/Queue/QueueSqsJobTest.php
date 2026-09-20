<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\Sqs\SqsClient;
use Closure;
use Exception;
use Hypervel\Container\Container as Application;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\ObjectPool\CallbackObjectPool;
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Queue\Jobs\SqsJob;
use Hypervel\Queue\SqsQueue;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;

class QueueSqsJobTest extends TestCase
{
    /** @var list<CallbackObjectPool> */
    private array $pools = [];

    protected string $key;

    protected string $secret;

    protected string $service;

    protected string $region;

    protected string $account;

    protected string $queueName;

    protected string $baseUrl;

    protected int $releaseDelay;

    protected string $queueUrl;

    protected SqsClient $mockedSqsClient;

    protected Container $mockedContainer;

    protected string $mockedJob;

    protected array $mockedData;

    protected string $mockedPayload;

    protected string $mockedMessageId;

    protected string $mockedReceiptHandle;

    protected array $mockedJobData;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->key = 'AMAZONSQSKEY';
        $this->secret = 'AmAz0n+SqSsEcReT+aLpHaNuM3R1CsTr1nG';
        $this->service = 'sqs';
        $this->region = 'someregion';
        $this->account = '1234567891011';
        $this->queueName = 'emails';
        $this->baseUrl = 'https://sqs.someregion.amazonaws.com';
        $this->releaseDelay = 0;

        // This is how the modified getQueue builds the queueUrl
        $this->queueUrl = $this->baseUrl . '/' . $this->account . '/' . $this->queueName;

        // Get a mock of the SqsClient
        $this->mockedSqsClient = m::mock(SqsClient::class)->makePartial();

        // Use Mockery to mock the IoC Container
        $this->mockedContainer = m::mock(Container::class);

        $this->mockedJob = 'foo';
        $this->mockedData = ['data'];
        $this->mockedPayload = json_encode(['job' => $this->mockedJob, 'data' => $this->mockedData, 'attempts' => 1]);
        $this->mockedMessageId = 'e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81';
        $this->mockedReceiptHandle = '0NNAq8PwvXuWv5gMtS9DJ8qEdyiUwbAjpp45w2m6M4SJ1Y+PxCh7R930NRB8ylSacEmoSnW18bgd4nK\/O6ctE+VFVul4eD23mA07vVoSnPI4F\/voI1eNCp6Iax0ktGmhlNVzBwaZHEr91BRtqTRM3QKd2ASF8u+IQaSwyl\/DGK+P1+dqUOodvOVtExJwdyDLy1glZVgm85Yw9Jf5yZEEErqRwzYz\/qSigdvW4sm2l7e4phRol\/+IjMtovOyH\/ukueYdlVbQ4OshQLENhUKe7RNN5i6bE\/e5x9bnPhfj2gbM';

        $this->mockedJobData = [
            'Body' => $this->mockedPayload,
            'MD5OfBody' => md5($this->mockedPayload),
            'ReceiptHandle' => $this->mockedReceiptHandle,
            'MessageId' => $this->mockedMessageId,
            'Attributes' => ['ApproximateReceiveCount' => 1],
        ];
    }

    /**
     * Close the pools owned by this test.
     */
    protected function tearDownInCoroutine(): void
    {
        foreach ($this->pools as $pool) {
            $pool->close();
        }
    }

    public function testFireProperlyCallsTheJobHandler(): void
    {
        $job = $this->getJob();
        $handler = m::mock(stdClass::class);
        $job->getContainer()->expects('make')->with('foo')->andReturn($handler);
        $handler->expects('fire')->with($job, ['data']);
        $job->fire();
    }

    public function testDeleteRemovesTheJobFromSqs(): void
    {
        $this->mockedSqsClient = m::mock(SqsClient::class)->makePartial();
        $queue = m::mock(SqsQueue::class, [$this->mockedSqsClient, $this->queueName, $this->account])->makePartial();
        $queue->setContainer($this->mockedContainer);
        $job = $this->getJob();
        $job->getSqs()->expects('deleteMessage')->with(['QueueUrl' => $this->queueUrl, 'ReceiptHandle' => $this->mockedReceiptHandle]);
        $job->delete();
    }

    public function testReleaseProperlyReleasesTheJobOntoSqs(): void
    {
        $this->mockedSqsClient = m::mock(SqsClient::class)->makePartial();
        $queue = m::mock(SqsQueue::class, [$this->mockedSqsClient, $this->queueName, $this->account])->makePartial();
        $queue->setContainer($this->mockedContainer);
        $job = $this->getJob();
        $job->getSqs()->expects('changeMessageVisibility')->with(['QueueUrl' => $this->queueUrl, 'ReceiptHandle' => $this->mockedReceiptHandle, 'VisibilityTimeout' => $this->releaseDelay]);
        $job->release($this->releaseDelay);
        $this->assertTrue($job->isReleased());
    }

    public function testGetRawBodyResolvesPointerFromCache(): void
    {
        $payload = json_encode(['job' => 'foo', 'data' => ['key' => 'value']], JSON_THROW_ON_ERROR);
        $pointer = 'laravel:sqs-payloads:some-uuid';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);

        $store = m::mock(CacheRepository::class);
        $store->expects('get')->with($pointer)->andReturn($payload);

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(Container::class);
        $container->expects('make')->with('cache')->andReturn($cache);

        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        $this->assertSame($payload, $job->getRawBody());
    }

    public function testGetRawBodyReturnsNormalBodyWithoutPointer(): void
    {
        $job = $this->getJob();

        $this->assertSame($this->mockedPayload, $job->getRawBody());
    }

    #[DataProvider('unavailableOverflowPayloadProvider')]
    public function testGetRawBodyCachesOriginalPointerWhenOverflowPayloadIsUnavailable(mixed $payload): void
    {
        $pointer = 'laravel:sqs-payloads:missing';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);

        $store = m::mock(CacheRepository::class);
        $store->expects('get')->with($pointer)->andReturn($payload);

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(Container::class);
        $container->expects('make')->with('cache')->andReturn($cache);

        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        $this->assertSame($pointerBody, $job->getRawBody());
        $this->assertSame($pointerBody, $job->getRawBody());
    }

    /**
     * Provide cache results that cannot contain a job payload.
     */
    public static function unavailableOverflowPayloadProvider(): array
    {
        return [
            'missing' => [null],
            'false' => [false],
            'array' => [['payload']],
            'object' => [(object) ['payload' => true]],
        ];
    }

    public function testGetRawBodyReturnsPointerBodyWhenExtendedStoreIsDisabled(): void
    {
        $pointerBody = json_encode([
            '@pointer' => 'laravel:sqs-payloads:disabled',
        ], JSON_THROW_ON_ERROR);

        $container = m::mock(Container::class);
        $container->shouldNotReceive('make');

        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
        );

        $this->assertSame($pointerBody, $job->getRawBody());
    }

    public function testGetRawBodyCachesResult(): void
    {
        $payload = json_encode(['job' => 'foo', 'data' => ['key' => 'value']], JSON_THROW_ON_ERROR);
        $pointer = 'laravel:sqs-payloads:some-uuid';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);

        $store = m::mock(CacheRepository::class);
        $store->expects('get')->with($pointer)->andReturn($payload);

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(Container::class);
        $container->expects('make')->with('cache')->andReturn($cache);

        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        // Call twice; cache should only be hit once.
        $job->getRawBody();
        $this->assertSame($payload, $job->getRawBody());
    }

    public function testDeleteCleansUpCacheKeyWhenCleanupEnabled(): void
    {
        $pointer = 'laravel:sqs-payloads:delete';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);
        $deletedFromSqs = false;

        $store = m::mock(CacheRepository::class);
        $store->expects('forget')->with($pointer)->andReturnUsing(
            function () use (&$deletedFromSqs): bool {
                $this->assertTrue($deletedFromSqs);

                return true;
            }
        );

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(Container::class);
        $container->expects('make')->with('cache')->andReturn($cache);

        $this->mockedSqsClient->expects('deleteMessage')->andReturnUsing(
            function () use (&$deletedFromSqs): void {
                $deletedFromSqs = true;
            }
        );

        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        $job->delete();
    }

    public function testDeleteDoesNotCleanUpWhenCleanupDisabled(): void
    {
        $pointerBody = json_encode([
            '@pointer' => 'laravel:sqs-payloads:retained',
        ], JSON_THROW_ON_ERROR);

        $container = m::mock(Container::class);
        $container->shouldNotReceive('make');
        $this->mockedSqsClient->expects('deleteMessage');

        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => false],
        );

        $job->delete();
    }

    public function testDeleteDoesNotCleanUpWhenNoPointer(): void
    {
        $container = m::mock(Container::class);
        $container->shouldNotReceive('make');
        $this->mockedSqsClient->expects('deleteMessage')->with([
            'QueueUrl' => $this->queueUrl,
            'ReceiptHandle' => $this->mockedReceiptHandle,
        ]);

        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            $this->mockedJobData,
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        $job->delete();
    }

    public function testReleaseNeverCleansOverflowPayload(): void
    {
        $pointerBody = json_encode([
            '@pointer' => 'laravel:sqs-payloads:released',
        ], JSON_THROW_ON_ERROR);

        $container = m::mock(Container::class);
        $container->shouldNotReceive('make');
        $this->mockedSqsClient->expects('changeMessageVisibility');

        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        $job->release();
    }

    public function testDeleteRetainsOverflowPayloadWhenSqsDeletionFails(): void
    {
        [$pool, $lease] = $this->lease();
        $pointerBody = json_encode([
            '@pointer' => 'laravel:sqs-payloads:retained',
        ], JSON_THROW_ON_ERROR);

        $container = m::mock(Container::class);
        $container->shouldNotReceive('make');
        $expected = new Exception('delete failed');
        $this->mockedSqsClient->expects('deleteMessage')->andThrow($expected);

        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        try {
            $job->withPoolLease($lease)->delete();
            $this->fail('The SQS deletion failure was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame($expected, $exception);
        }

        $this->assertSame(0, $pool->getManagedCount());
    }

    public function testDeleteCleansOverflowPayloadAfterLeaseReleaseFails(): void
    {
        $releaseFailure = new Exception('release failed');
        [, $lease] = $this->lease(
            releaseCallback: static fn () => throw $releaseFailure,
        );
        $pointer = 'laravel:sqs-payloads:cleanup';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);

        $store = m::mock(CacheRepository::class);
        $store->expects('forget')->with($pointer)->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(Container::class);
        $container->expects('make')->with('cache')->andReturn($cache);
        $this->mockedSqsClient->expects('deleteMessage');

        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        try {
            $job->withPoolLease($lease)->delete();
            $this->fail('The lease release failure was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame($releaseFailure, $exception);
        }
    }

    public function testDeleteDoesNotStartOverflowCleanupAfterLeaseReleaseCancellation(): void
    {
        $releaseCancellation = new CanceledException('release canceled');
        [, $lease] = $this->lease(
            releaseCallback: static fn () => throw $releaseCancellation,
        );
        $pointer = 'laravel:sqs-payloads:cleanup';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);
        $store = m::spy(CacheRepository::class);
        $cache = m::mock(CacheFactory::class);
        $cache->allows('store')->andReturn($store);
        $container = m::mock(Container::class);
        $container->allows('make')->andReturn($cache);
        $this->mockedSqsClient->expects('deleteMessage');
        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        try {
            $job->withPoolLease($lease)->delete();
            $this->fail('Expected lease release cancellation to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($releaseCancellation, $exception);
        }

        $store->shouldNotHaveReceived('forget');
    }

    public function testDeleteSurfacesOverflowCleanupFailureAfterSuccessfulDeletion(): void
    {
        $pointer = 'laravel:sqs-payloads:cleanup';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);

        $store = m::mock(CacheRepository::class);
        $store->expects('forget')->with($pointer)->andReturnFalse();

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(Container::class);
        $container->expects('make')->with('cache')->andReturn($cache);
        $this->mockedSqsClient->expects('deleteMessage');

        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to delete the SQS overflow payload [{$pointer}].");

        $job->delete();
    }

    public function testDeleteReportsCleanupFailureWithoutReplacingLeaseReleaseFailure(): void
    {
        $releaseFailure = new Exception('release failed');
        $cleanupFailure = null;
        [, $lease] = $this->lease(
            releaseCallback: static fn () => throw $releaseFailure,
        );
        $pointer = 'laravel:sqs-payloads:cleanup';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);

        $store = m::mock(CacheRepository::class);
        $store->expects('forget')->with($pointer)->andReturnFalse();

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(Container::class);
        $container->expects('make')->with('cache')->andReturn($cache);
        $this->mockedSqsClient->expects('deleteMessage');

        $handler = m::mock(ExceptionHandler::class);
        $handler->expects('report')->withArgs(
            function (Throwable $exception) use (&$cleanupFailure): bool {
                $cleanupFailure = $exception;

                return true;
            }
        );

        $application = new Application;
        $application->instance(ExceptionHandler::class, $handler);
        Application::setInstance($application);

        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        try {
            $job->withPoolLease($lease)->delete();
            $this->fail('The lease release failure was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame($releaseFailure, $exception);
        }

        $this->assertInstanceOf(RuntimeException::class, $cleanupFailure);
        $this->assertSame("Unable to delete the SQS overflow payload [{$pointer}].", $cleanupFailure->getMessage());
    }

    public function testOverflowCleanupCancellationSupersedesAnOrdinaryLeaseReleaseFailure(): void
    {
        $releaseFailure = new Exception('release failed');
        $cleanupCancellation = new CanceledException('overflow cleanup canceled');
        [, $lease] = $this->lease(
            releaseCallback: static fn () => throw $releaseFailure,
        );
        $pointer = 'laravel:sqs-payloads:cleanup';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);
        $store = m::mock(CacheRepository::class);
        $store->expects('forget')->with($pointer)->andThrow($cleanupCancellation);
        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);
        $container = m::mock(Container::class);
        $container->expects('make')->with('cache')->andReturn($cache);
        $this->mockedSqsClient->expects('deleteMessage');
        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        try {
            $job->withPoolLease($lease)->delete();
            $this->fail('Expected overflow cleanup cancellation to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($cleanupCancellation, $exception);
        }
    }

    public function testDeleteReleasesPoolLeaseAfterBackendCall(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getSqs()->expects('deleteMessage')
            ->andReturnUsing(function () use ($pool): void {
                $this->assertSame(1, $pool->getBorrowedCount());
            });

        $job->withPoolLease($lease)->delete();

        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testReleaseReleasesPoolLeaseAfterBackendCall(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getSqs()->expects('changeMessageVisibility')
            ->andReturnUsing(function () use ($pool): void {
                $this->assertSame(1, $pool->getBorrowedCount());
            });

        $job->withPoolLease($lease)->release(5);

        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testBackendFailureDiscardsPoolLeaseAndPreservesTheException(): void
    {
        $destroyed = 0;
        [$pool, $lease] = $this->lease(function () use (&$destroyed): void {
            ++$destroyed;
        });
        $job = $this->getJob();
        $expected = new Exception('delete failed');
        $job->getSqs()->expects('deleteMessage')->andThrow($expected);

        try {
            $job->withPoolLease($lease)->delete();
            $this->fail('The backend exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame($expected, $exception);
        }

        $this->assertSame(1, $destroyed);
        $this->assertSame(0, $pool->getManagedCount());
        $this->assertSame(0, $pool->getBorrowedCount());
    }

    public function testDiscardCancellationSupersedesAnOrdinaryBackendFailure(): void
    {
        $backendFailure = new Exception('delete failed');
        $discardCancellation = new CanceledException('discard canceled');
        [$pool, $lease] = $this->lease(static function () use ($discardCancellation): never {
            throw $discardCancellation;
        });
        $job = $this->getJob();
        $job->getSqs()->expects('deleteMessage')->andThrow($backendFailure);

        try {
            $job->withPoolLease($lease)->delete();
            $this->fail('Expected discard cancellation to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($discardCancellation, $exception);
        }

        $this->assertSame(0, $pool->getManagedCount());
        $this->assertSame(0, $pool->getBorrowedCount());
    }

    public function testBackendAccessIsRejectedAfterLeaseFinalization(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getSqs()->expects('deleteMessage');

        $job->withPoolLease($lease)->delete();
        $this->assertSame(0, $pool->getBorrowedCount());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('client is no longer available');

        $job->getSqs();
    }

    /**
     * Create a checked-out object under a queue-job lease.
     *
     * @return array{CallbackObjectPool, Lease}
     */
    protected function lease(?Closure $destroyCallback = null, ?Closure $releaseCallback = null): array
    {
        $pool = new CallbackObjectPool(
            fn () => new stdClass,
            PoolOptions::fromArray([]),
            $destroyCallback,
        );
        $this->pools[] = $pool;

        return [$pool, new Lease($pool, $pool->borrow(), $releaseCallback)];
    }

    /**
     * Create the SQS job for the test payload.
     */
    protected function getJob(): SqsJob
    {
        return new SqsJob(
            $this->mockedContainer,
            $this->mockedSqsClient,
            $this->mockedJobData,
            'connection-name',
            $this->queueUrl
        );
    }
}
