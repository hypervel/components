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
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\SimpleObjectPool;
use Hypervel\Queue\Jobs\SqsJob;
use Hypervel\Queue\SqsQueue;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Throwable;

class QueueSqsJobTest extends TestCase
{
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

    public function testFireProperlyCallsTheJobHandler(): void
    {
        $job = $this->getJob();
        $job->getContainer()->shouldReceive('make')->once()->with('foo')->andReturn($handler = m::mock(stdClass::class));
        $handler->shouldReceive('fire')->once()->with($job, ['data']);
        $job->fire();
    }

    public function testDeleteRemovesTheJobFromSqs(): void
    {
        $this->mockedSqsClient = m::mock(SqsClient::class)->makePartial();
        $queue = m::mock(SqsQueue::class, [$this->mockedSqsClient, $this->queueName, $this->account])->makePartial();
        $queue->setContainer($this->mockedContainer);
        $job = $this->getJob();
        $job->getSqs()->shouldReceive('deleteMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'ReceiptHandle' => $this->mockedReceiptHandle]);
        $job->delete();
    }

    public function testReleaseProperlyReleasesTheJobOntoSqs(): void
    {
        $this->mockedSqsClient = m::mock(SqsClient::class)->makePartial();
        $queue = m::mock(SqsQueue::class, [$this->mockedSqsClient, $this->queueName, $this->account])->makePartial();
        $queue->setContainer($this->mockedContainer);
        $job = $this->getJob();
        $job->getSqs()->shouldReceive('changeMessageVisibility')->once()->with(['QueueUrl' => $this->queueUrl, 'ReceiptHandle' => $this->mockedReceiptHandle, 'VisibilityTimeout' => $this->releaseDelay]);
        $job->release($this->releaseDelay);
        $this->assertTrue($job->isReleased());
    }

    public function testGetRawBodyResolvesAndCachesOverflowPointer(): void
    {
        $payload = json_encode(['job' => 'foo', 'data' => ['key' => 'value']], JSON_THROW_ON_ERROR);
        $pointer = 'laravel:sqs-payloads:some-uuid';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);

        $store = m::mock(CacheRepository::class);
        $store->shouldReceive('get')->once()->with($pointer)->andReturn($payload);

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);

        $job = new SqsJob(
            $container,
            $this->mockedSqsClient,
            [...$this->mockedJobData, 'Body' => $pointerBody],
            'connection-name',
            $this->queueUrl,
            ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );

        $this->assertSame($payload, $job->getRawBody());
        $this->assertSame($payload, $job->getRawBody());
    }

    #[DataProvider('unavailableOverflowPayloadProvider')]
    public function testGetRawBodyCachesOriginalPointerWhenOverflowPayloadIsUnavailable(mixed $payload): void
    {
        $pointer = 'laravel:sqs-payloads:missing';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);

        $store = m::mock(CacheRepository::class);
        $store->shouldReceive('get')->once()->with($pointer)->andReturn($payload);

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);

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

    public static function unavailableOverflowPayloadProvider(): array
    {
        return [
            'missing' => [null],
            'false' => [false],
            'array' => [['payload']],
            'object' => [(object) ['payload' => true]],
        ];
    }

    public function testGetRawBodyDoesNotResolvePointerWhenOverflowStorageIsDisabled(): void
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

    public function testDeleteCleansOverflowPayloadAfterDeletingFromSqs(): void
    {
        $pointer = 'laravel:sqs-payloads:delete';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);
        $deletedFromSqs = false;

        $store = m::mock(CacheRepository::class);
        $store->shouldReceive('forget')->once()->with($pointer)->andReturnUsing(
            function () use (&$deletedFromSqs): bool {
                $this->assertTrue($deletedFromSqs);

                return true;
            }
        );

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);

        $this->mockedSqsClient->shouldReceive('deleteMessage')->once()->andReturnUsing(
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

    public function testDeleteDoesNotCleanOverflowPayloadWhenCleanupIsDisabled(): void
    {
        $pointerBody = json_encode([
            '@pointer' => 'laravel:sqs-payloads:retained',
        ], JSON_THROW_ON_ERROR);

        $container = m::mock(Container::class);
        $container->shouldNotReceive('make');
        $this->mockedSqsClient->shouldReceive('deleteMessage')->once();

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

    public function testReleaseNeverCleansOverflowPayload(): void
    {
        $pointerBody = json_encode([
            '@pointer' => 'laravel:sqs-payloads:released',
        ], JSON_THROW_ON_ERROR);

        $container = m::mock(Container::class);
        $container->shouldNotReceive('make');
        $this->mockedSqsClient->shouldReceive('changeMessageVisibility')->once();

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
        $this->mockedSqsClient->shouldReceive('deleteMessage')->once()->andThrow($expected);

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

        $this->assertSame(0, $pool->getCurrentObjectNumber());
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
        $store->shouldReceive('forget')->once()->with($pointer)->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);
        $this->mockedSqsClient->shouldReceive('deleteMessage')->once();

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

    public function testDeleteSurfacesOverflowCleanupFailureAfterSuccessfulDeletion(): void
    {
        $pointer = 'laravel:sqs-payloads:cleanup';
        $pointerBody = json_encode(['@pointer' => $pointer], JSON_THROW_ON_ERROR);

        $store = m::mock(CacheRepository::class);
        $store->shouldReceive('forget')->once()->with($pointer)->andReturnFalse();

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);
        $this->mockedSqsClient->shouldReceive('deleteMessage')->once();

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
        $store->shouldReceive('forget')->once()->with($pointer)->andReturnFalse();

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);
        $this->mockedSqsClient->shouldReceive('deleteMessage')->once();

        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->withArgs(
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

    public function testDeleteReleasesPoolLeaseAfterBackendCall(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getSqs()->shouldReceive('deleteMessage')->once()
            ->andReturnUsing(function () use ($pool): void {
                $this->assertSame(1, $pool->getBorrowedObjectNumber());
            });

        $job->withPoolLease($lease)->delete();

        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    public function testReleaseReleasesPoolLeaseAfterBackendCall(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getSqs()->shouldReceive('changeMessageVisibility')->once()
            ->andReturnUsing(function () use ($pool): void {
                $this->assertSame(1, $pool->getBorrowedObjectNumber());
            });

        $job->withPoolLease($lease)->release(5);

        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    public function testBackendFailureDiscardsPoolLeaseAndPreservesTheException(): void
    {
        $destroyed = 0;
        [$pool, $lease] = $this->lease(function () use (&$destroyed): void {
            ++$destroyed;
        });
        $job = $this->getJob();
        $expected = new Exception('delete failed');
        $job->getSqs()->shouldReceive('deleteMessage')->once()->andThrow($expected);

        try {
            $job->withPoolLease($lease)->delete();
            $this->fail('The backend exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame($expected, $exception);
        }

        $this->assertSame(1, $destroyed);
        $this->assertSame(0, $pool->getCurrentObjectNumber());
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
    }

    public function testBackendAccessIsRejectedAfterLeaseFinalization(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getSqs()->shouldReceive('deleteMessage')->once();

        $job->withPoolLease($lease)->delete();
        $this->assertSame(0, $pool->getBorrowedObjectNumber());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('client is no longer available');

        $job->getSqs();
    }

    /**
     * Create a checked-out object under a queue-job lease.
     *
     * @return array{SimpleObjectPool, Lease}
     */
    protected function lease(?Closure $destroyCallback = null, ?Closure $releaseCallback = null): array
    {
        $pool = new SimpleObjectPool(
            fn () => new stdClass,
            PoolOptions::fromArray([]),
            $destroyCallback,
        );

        return [$pool, new Lease($pool, $pool->get(), $releaseCallback)];
    }

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
