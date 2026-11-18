<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\MockHandler;
use Aws\Result;
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
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;

class QueueSqsJobTest extends TestCase
{
    use VerifiesDoubles;

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

    protected MockHandler $handler;

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

        // A real SqsClient that never leaves the process.
        $this->handler = new MockHandler;
        $this->mockedSqsClient = new SqsClient([
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => ['key' => $this->key, 'secret' => $this->secret],
            'handler' => $this->handler,
        ]);

        $this->mockedContainer = new Application;

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

        $this->assertCount(0, $this->handler);
    }

    public function testFireProperlyCallsTheJobHandler(): void
    {
        $job = $this->getJob();
        $handler = new SqsJobTestHandler;
        $job->getContainer()->instance('foo', $handler);
        $job->fire();

        $this->assertSame([[$job, ['data']]], $handler->fired);
    }

    public function testDeleteRemovesTheJobFromSqs(): void
    {
        $this->handler->append(new Result([]));
        $job = $this->getJob();
        $job->delete();

        $this->assertSame('DeleteMessage', $this->handler->getLastCommand()->getName());
        $this->assertSame([
            'QueueUrl' => $this->queueUrl,
            'ReceiptHandle' => $this->mockedReceiptHandle,
        ], json_decode((string) $this->handler->getLastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testReleaseProperlyReleasesTheJobOntoSqs(): void
    {
        $this->handler->append(new Result([]));
        $job = $this->getJob();
        $job->release($this->releaseDelay);
        $this->assertTrue($job->isReleased());

        $this->assertSame('ChangeMessageVisibility', $this->handler->getLastCommand()->getName());
        $this->assertSame([
            'QueueUrl' => $this->queueUrl,
            'ReceiptHandle' => $this->mockedReceiptHandle,
            'VisibilityTimeout' => $this->releaseDelay,
        ], json_decode((string) $this->handler->getLastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testGetRawBodyResolvesPointerFromCache(): void
    {
        $payload = json_encode(['job' => 'foo', 'data' => ['key' => 'value']], JSON_THROW_ON_ERROR);
        $pointer = 'laravel:sqs-payloads:some-uuid';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('get')->with($pointer)->returns($payload);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with('cache')->returns($cache);

        $job = new SqsJob(
            $container->instance(),
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

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('get')->with($pointer)->returns($payload);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with('cache')->returns($cache);

        $job = new SqsJob(
            $container->instance(),
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

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->never();

        $job = new SqsJob(
            $container->instance(),
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

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('get')->with($pointer)->returns($payload);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with('cache')->returns($cache);

        $job = new SqsJob(
            $container->instance(),
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

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('forget')->with($pointer)->resolves(
            function (): bool {
                $this->assertSame('DeleteMessage', $this->handler->getLastCommand()->getName());

                return true;
            }
        );

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with('cache')->returns($cache);

        $this->handler->append(new Result([]));

        $job = new SqsJob(
            $container->instance(),
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

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->never();
        $this->handler->append(new Result([]));

        $job = new SqsJob(
            $container->instance(),
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
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->never();
        $this->handler->append(new Result([]));

        $job = new SqsJob(
            $container->instance(),
            $this->mockedSqsClient,
            $this->mockedJobData,
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        $job->delete();

        $this->assertSame([
            'QueueUrl' => $this->queueUrl,
            'ReceiptHandle' => $this->mockedReceiptHandle,
        ], json_decode((string) $this->handler->getLastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testReleaseNeverCleansOverflowPayload(): void
    {
        $pointerBody = json_encode([
            '@pointer' => 'laravel:sqs-payloads:released',
        ], JSON_THROW_ON_ERROR);

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->never();
        $this->handler->append(new Result([]));

        $job = new SqsJob(
            $container->instance(),
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

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->never();
        $expected = new Exception('delete failed');
        $this->handler->append($expected);

        $job = new SqsJob(
            $container->instance(),
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

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('forget')->with($pointer)->returns(true);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with('cache')->returns($cache);
        $this->handler->append(new Result([]));

        $job = new SqsJob(
            $container->instance(),
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

    #[TestWith([false])]
    #[TestWith([true])]
    public function testDeleteDoesNotStartOverflowCleanupAfterLeaseReleaseCancellation(bool $cleanupThrows): void
    {
        $releaseCancellation = new CanceledException('release canceled');
        if ($cleanupThrows) {
            $releaseCancellation = new RuntimeException('Release cleanup failed.', previous: $releaseCancellation);
        }
        [, $lease] = $this->lease(
            releaseCallback: static fn () => throw $releaseCancellation,
        );
        $pointer = 'laravel:sqs-payloads:cleanup';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);
        $store = Double::for(CacheRepository::class);
        $cache = Double::for(CacheFactory::class)->strict();
        $cache->allows('store')->returns($store);
        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('make')->returns($cache);
        $this->handler->append(new Result([]));
        $job = new SqsJob(
            $container->instance(),
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

        $store->received('forget')->never();
    }

    public function testDeleteSurfacesOverflowCleanupFailureAfterSuccessfulDeletion(): void
    {
        $pointer = 'laravel:sqs-payloads:cleanup';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('forget')->with($pointer)->returns(false);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with('cache')->returns($cache);
        $this->handler->append(new Result([]));

        $job = new SqsJob(
            $container->instance(),
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("Unable to delete the SQS overflow payload [{$pointer}].");

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

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('forget')->with($pointer)->returns(false);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with('cache')->returns($cache);
        $this->handler->append(new Result([]));

        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::capture($cleanupFailure));

        $application = new Application;
        $application->instance(ExceptionHandler::class, $handler);
        Application::setInstance($application);

        $job = new SqsJob(
            $container->instance(),
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

    #[TestWith([false])]
    #[TestWith([true])]
    public function testOverflowCleanupCancellationSupersedesAnOrdinaryLeaseReleaseFailure(bool $cleanupThrows): void
    {
        $releaseFailure = new Exception('release failed');
        $cleanupCancellation = new CanceledException('overflow cleanup canceled');
        if ($cleanupThrows) {
            $cleanupCancellation = new RuntimeException('Overflow cleanup failed.', previous: $cleanupCancellation);
        }
        [, $lease] = $this->lease(
            releaseCallback: static fn () => throw $releaseFailure,
        );
        $pointer = 'laravel:sqs-payloads:cleanup';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);
        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('forget')->with($pointer)->throws($cleanupCancellation);
        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with('cache')->returns($cache);
        $this->handler->append(new Result([]));
        $job = new SqsJob(
            $container->instance(),
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
        $this->handler->append(function () use ($pool): Result {
            $this->assertSame(1, $pool->getBorrowedCount());

            return new Result([]);
        });

        $job->withPoolLease($lease)->delete();

        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testReleaseReleasesPoolLeaseAfterBackendCall(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $this->handler->append(function () use ($pool): Result {
            $this->assertSame(1, $pool->getBorrowedCount());

            return new Result([]);
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
        $this->handler->append($expected);

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
        $this->handler->append($backendFailure);

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
        $this->handler->append(new Result([]));

        $job->withPoolLease($lease)->delete();
        $this->assertSame(0, $pool->getBorrowedCount());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('client is no longer available');

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

class SqsJobTestHandler
{
    public array $fired = [];

    /**
     * Record the job and its payload.
     */
    public function fire(SqsJob $job, array $data): void
    {
        $this->fired[] = [$job, $data];
    }
}
