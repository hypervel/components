<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use Aws\Sqs\SqsClient;
use Closure;
use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Cache\Store as CacheStore;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Events\Dispatcher as ConcreteEventDispatcher;
use Hypervel\Foundation\Queue\Queueable;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Events\JobPayloadFinalizing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Events\JobQueueingCanceled;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\Jobs\SqsJob;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Queue\SqsQueue;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Tests\Queue\Fixtures\FakeSqsJob;
use Hypervel\Tests\Queue\Fixtures\FakeSqsJobWithDeduplication;
use Hypervel\Tests\Queue\Fixtures\FakeSqsJobWithDelayAttribute;
use Hypervel\Tests\Queue\Fixtures\FakeSqsJobWithMessageGroup;
use Hypervel\Tests\Queue\Fixtures\IntegerQueueName;
use Hypervel\Tests\Queue\Fixtures\UnitQueueName;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use JMac\Testing\OverriddenDouble;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Uid\Uuid;
use Throwable;

class QueueSqsQueueTest extends TestCase
{
    use VerifiesDoubles;

    protected MockHandler $handler;

    protected SqsClient $sqs;

    protected string $account;

    protected string $queueName;

    protected string $baseUrl;

    protected string $prefix;

    protected string $queueUrl;

    protected string $mockedJob;

    protected array $mockedData;

    protected string $mockedPayload;

    protected int $mockedDelay;

    protected string $mockedMessageId;

    protected string $mockedReceiptHandle;

    protected Result $mockedSendMessageResponseModel;

    protected Result $mockedReceiveMessageResponseModel;

    protected Result $mockedReceiveEmptyMessageResponseModel;

    protected string $fifoQueueName;

    protected string $fifoQueueUrl;

    protected string $mockedMessageGroupId;

    protected string $mockedDeduplicationId;

    protected Result $mockedQueueAttributesResponseModel;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // A real SqsClient whose requests never leave the process.
        $this->handler = new MockHandler;
        $this->sqs = new SqsClient([
            'region' => 'someregion',
            'version' => 'latest',
            'credentials' => ['key' => 'AMAZONSQSKEY', 'secret' => 'AmAz0n+SqSsEcReT+aLpHaNuM3R1CsTr1nG'],
            'handler' => $this->handler,
        ]);

        $this->account = '1234567891011';
        $this->queueName = 'emails';
        $this->baseUrl = 'https://sqs.someregion.amazonaws.com';

        // This is how the modified getQueue builds the queueUrl
        $this->prefix = $this->baseUrl . '/' . $this->account . '/';
        $this->queueUrl = $this->prefix . $this->queueName;
        $this->fifoQueueName = 'emails.fifo';
        $this->fifoQueueUrl = $this->prefix . $this->fifoQueueName;
        $this->mockedMessageGroupId = 'group-1';
        $this->mockedDeduplicationId = 'a74be397-1cca-4e2b-b498-315025793687';

        $this->mockedJob = 'foo';
        $this->mockedData = ['data'];
        $this->mockedPayload = json_encode(['job' => $this->mockedJob, 'data' => $this->mockedData]);
        $this->mockedDelay = 10;
        $this->mockedMessageId = 'e3cd03ee-59a3-4ad8-b0aa-ee2e3808ac81';
        $this->mockedReceiptHandle = '0NNAq8PwvXuWv5gMtS9DJ8qEdyiUwbAjpp45w2m6M4SJ1Y+PxCh7R930NRB8ylSacEmoSnW18bgd4nK\/O6ctE+VFVul4eD23mA07vVoSnPI4F\/voI1eNCp6Iax0ktGmhlNVzBwaZHEr91BRtqTRM3QKd2ASF8u+IQaSwyl\/DGK+P1+dqUOodvOVtExJwdyDLy1glZVgm85Yw9Jf5yZEEErqRwzYz\/qSigdvW4sm2l7e4phRol\/+IjMtovOyH\/ukueYdlVbQ4OshQLENhUKe7RNN5i6bE\/e5x9bnPhfj2gbM';

        $this->mockedSendMessageResponseModel = new Result([
            'Body' => $this->mockedPayload,
            'MD5OfBody' => md5($this->mockedPayload),
            'ReceiptHandle' => $this->mockedReceiptHandle,
            'MessageId' => $this->mockedMessageId,
            'Attributes' => ['ApproximateReceiveCount' => 1],
        ]);

        $this->mockedReceiveMessageResponseModel = new Result([
            'Messages' => [
                0 => [
                    'Body' => $this->mockedPayload,
                    'MD5OfBody' => md5($this->mockedPayload),
                    'ReceiptHandle' => $this->mockedReceiptHandle,
                    'MessageId' => $this->mockedMessageId,
                ],
            ],
        ]);

        $this->mockedReceiveEmptyMessageResponseModel = new Result([
            'Messages' => null,
        ]);

        $this->mockedQueueAttributesResponseModel = new Result([
            'Attributes' => [
                'ApproximateNumberOfMessages' => 1,
            ],
        ]);
    }

    /**
     * Queue the next expected SQS command and the response (or exception) to send back.
     *
     * @param null|array|callable $parameters The exact parameters, or a callback that returns true when they are right.
     *                                        An array value that is a closure is called with the actual value instead.
     */
    protected function expectSqsCommand(string $name, array|callable|null $parameters = null, Result|Throwable|callable|null $result = null, int $times = 1): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->handler->append(function (CommandInterface $command) use ($name, $parameters, $result): Result|Throwable {
                $this->assertSame(ucfirst($name), $command->getName());

                $arguments = array_filter($command->toArray(), fn (string $key): bool => ! str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY);

                if (is_array($parameters)) {
                    foreach ($parameters as $key => $expected) {
                        if ($expected instanceof Closure) {
                            $this->assertTrue($expected($arguments[$key] ?? null), "Unexpected [{$key}] for the [{$name}] command.");
                            unset($parameters[$key], $arguments[$key]);
                        }
                    }

                    $this->assertEquals($parameters, $arguments);
                } elseif (is_callable($parameters)) {
                    $this->assertNotFalse($parameters($arguments), "Unexpected parameters for the [{$name}] command.");
                }

                if (is_callable($result)) {
                    $result = $result($arguments);
                }

                return $result ?? new Result([]);
            });
        }
    }

    /**
     * Get a callback that checks a message body is the payload SqsQueue creates for a job class or job string.
     */
    protected function isPayloadOf(string $job): Closure
    {
        return function (string $body) use ($job): bool {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

            if ($payload['displayName'] !== $job) {
                return false;
            }

            if (! class_exists($job)) {
                return $payload['data'] === $this->mockedData;
            }

            return $payload['data']['commandName'] === $job
                && unserialize($payload['data']['command']) instanceof $job;
        };
    }

    /**
     * Reset fixture factories and verify that every expected request was sent.
     */
    protected function tearDown(): void
    {
        FakeSqsJobWithMessageGroup::createMessageGroupsNormally();
        FakeSqsJobWithDeduplication::createDeduplicationIdsNormally();

        try {
            $this->assertCount(0, $this->handler, 'Not every expected SQS command was sent.');
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Create the UUID returned by the test resolver.
     */
    protected function createMockedUuid(string $value): Uuid
    {
        return Uuid::fromString($value);
    }

    /**
     * Create a container spy with real service resolution.
     */
    protected function createSpyContainer(): OverriddenDouble
    {
        return Double::for(Container::class, override: true)->passthru(new Container);
    }

    public function testPopProperlyPopsJobOffOfSqs(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer(new Container);
        $queue->setConnectionName('sqs');
        $this->expectSqsCommand('receiveMessage', ['QueueUrl' => $this->queueUrl, 'MessageSystemAttributeNames' => ['ApproximateReceiveCount']], $this->mockedReceiveMessageResponseModel);
        $result = $queue->pop($this->queueName);
        $this->assertInstanceOf(SqsJob::class, $result);
    }

    public function testPopProperlyHandlesEmptyMessage(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer(new Container);
        $this->expectSqsCommand('receiveMessage', ['QueueUrl' => $this->queueUrl, 'MessageSystemAttributeNames' => ['ApproximateReceiveCount']], $this->mockedReceiveEmptyMessageResponseModel);
        $result = $queue->pop($this->queueName);
        $this->assertNull($result);
    }

    public function testDelayedPushWithDateTimeProperlyPushesJobOntoSqs(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now()->startOfSecond());
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', ['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->isPayloadOf($this->mockedJob), 'DelaySeconds' => 5], $this->mockedSendMessageResponseModel);
        $id = $queue->later($now->addSeconds(5), $this->mockedJob, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->received('bound')->with('events')->times(3);
    }

    public function testDelayedPushProperlyPushesJobOntoSqs(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', ['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->isPayloadOf($this->mockedJob), 'DelaySeconds' => $this->mockedDelay], $this->mockedSendMessageResponseModel);
        $id = $queue->later($this->mockedDelay, $this->mockedJob, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->received('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobOntoSqs(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', ['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->isPayloadOf($this->mockedJob)], $this->mockedSendMessageResponseModel);
        $id = $queue->push($this->mockedJob, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->received('bound')->with('events')->times(3);
    }

    #[DataProvider('queueDefaultingDataProvider')]
    public function testPushPreservesZeroQueueAndDefaultsEmptyQueue(string $requestedQueue, string $logicalQueue): void
    {
        $queueUrl = $this->prefix . $logicalQueue;
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload', 'getQueue'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer($this->createSpyContainer()->instance());
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $logicalQueue, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($requestedQueue)->willReturn($queueUrl);
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $queueUrl,
            'MessageBody' => $this->mockedPayload,
        ], $this->mockedSendMessageResponseModel);

        $this->assertSame(
            $this->mockedMessageId,
            $queue->push($this->mockedJob, $this->mockedData, $requestedQueue)
        );
    }

    #[DataProvider('queueDefaultingDataProvider')]
    public function testLaterPreservesZeroQueueAndDefaultsEmptyQueue(string $requestedQueue, string $logicalQueue): void
    {
        $queueUrl = $this->prefix . $logicalQueue;
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload', 'getQueue', 'secondsUntil'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer($this->createSpyContainer()->instance());
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $logicalQueue, $this->mockedData, $this->mockedDelay)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($requestedQueue)->willReturn($queueUrl);
        $queue->expects($this->once())->method('secondsUntil')->with($this->mockedDelay)->willReturn($this->mockedDelay);
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $queueUrl,
            'MessageBody' => $this->mockedPayload,
            'DelaySeconds' => $this->mockedDelay,
        ], $this->mockedSendMessageResponseModel);

        $this->assertSame(
            $this->mockedMessageId,
            $queue->later($this->mockedDelay, $this->mockedJob, $this->mockedData, $requestedQueue)
        );
    }

    /**
     * Provide queue names that distinguish zero from an empty default.
     */
    public static function queueDefaultingDataProvider(): array
    {
        return [
            'preserves zero queue' => ['0', '0'],
            'defaults empty queue' => ['', 'emails'],
        ];
    }

    public function testSizeProperlyReadsSqsQueueSize(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);

        $this->expectSqsCommand('getQueueAttributes', [
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => [
                'ApproximateNumberOfMessages',
                'ApproximateNumberOfMessagesDelayed',
                'ApproximateNumberOfMessagesNotVisible',
            ],
        ], new Result([
            'Attributes' => [
                'ApproximateNumberOfMessages' => 1,
                'ApproximateNumberOfMessagesDelayed' => 2,
                'ApproximateNumberOfMessagesNotVisible' => 3,
            ],
        ]));

        $size = $queue->size($this->queueName);

        $this->assertEquals(6, $size); // 1 + 2 + 3
    }

    public function testPendingSizeProperlyReadsSqsQueuePendingSize(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);

        $this->expectSqsCommand('getQueueAttributes', [
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessages'],
        ], new Result([
            'Attributes' => [
                'ApproximateNumberOfMessages' => 1,
            ],
        ]));

        $this->assertSame(1, $queue->pendingSize($this->queueName));

        // Test missing attribute fallback
        $this->expectSqsCommand('getQueueAttributes', [
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessages'],
        ], new Result([
            'Attributes' => [],
        ]));

        $this->assertSame(0, $queue->pendingSize($this->queueName));
    }

    public function testDelayedSizeProperlyReadsSqsQueueDelayedSize(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);

        $this->expectSqsCommand('getQueueAttributes', [
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessagesDelayed'],
        ], new Result([
            'Attributes' => [
                'ApproximateNumberOfMessagesDelayed' => 2,
            ],
        ]));

        $this->assertSame(2, $queue->delayedSize($this->queueName));

        // Test missing attribute fallback
        $this->expectSqsCommand('getQueueAttributes', [
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessagesDelayed'],
        ], new Result([
            'Attributes' => [],
        ]));

        $this->assertSame(0, $queue->delayedSize($this->queueName));
    }

    public function testReservedSizeProperlyReadsSqsQueueReservedSize(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);

        $this->expectSqsCommand('getQueueAttributes', [
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessagesNotVisible'],
        ], new Result([
            'Attributes' => [
                'ApproximateNumberOfMessagesNotVisible' => 3,
            ],
        ]));

        $this->assertSame(3, $queue->reservedSize($this->queueName));

        // Test missing attribute fallback
        $this->expectSqsCommand('getQueueAttributes', [
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessagesNotVisible'],
        ], new Result([
            'Attributes' => [],
        ]));

        $this->assertSame(0, $queue->reservedSize($this->queueName));
    }

    public function testInspectionReturnsEmptyCollections(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);

        $this->assertTrue($queue->pendingJobs()->isEmpty());
        $this->assertTrue($queue->delayedJobs()->isEmpty());
        $this->assertTrue($queue->reservedJobs()->isEmpty());
        $this->assertTrue($queue->allPendingJobs()->isEmpty());
        $this->assertTrue($queue->allDelayedJobs()->isEmpty());
        $this->assertTrue($queue->allReservedJobs()->isEmpty());
    }

    public function testGetQueueProperlyResolvesUrlWithPrefix(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $this->assertEquals($this->queueUrl, $queue->getQueue(null));
        $this->assertEquals($this->queueUrl, $queue->getQueue(''));
        $this->assertEquals($this->prefix . '0', $queue->getQueue('0'));
        $this->assertSame($this->prefix . '0', $queue->getQueue(IntegerQueueName::Zero));
        $this->assertSame($this->prefix . 'Emails', $queue->getQueue(UnitQueueName::Emails));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test';
        $this->assertEquals($queueUrl, $queue->getQueue('test'));
    }

    public function testGetQueueProperlyResolvesFifoUrlWithPrefix(): void
    {
        $this->queueName = 'emails.fifo';
        $this->queueUrl = $this->prefix . $this->queueName;
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $this->assertEquals($this->queueUrl, $queue->getQueue(null));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test.fifo';
        $this->assertEquals($queueUrl, $queue->getQueue('test.fifo'));
    }

    public function testGetQueueProperlyResolvesUrlWithoutPrefix(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueUrl);
        $this->assertEquals($this->queueUrl, $queue->getQueue(null));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test';
        $this->assertEquals($queueUrl, $queue->getQueue($queueUrl));
    }

    public function testGetQueueProperlyResolvesFifoUrlWithoutPrefix(): void
    {
        $this->queueName = 'emails.fifo';
        $this->queueUrl = $this->prefix . $this->queueName;
        $queue = new SqsQueue($this->sqs, $this->queueUrl);
        $this->assertEquals($this->queueUrl, $queue->getQueue(null));
        $fifoQueueUrl = $this->baseUrl . '/' . $this->account . '/test.fifo';
        $this->assertEquals($fifoQueueUrl, $queue->getQueue($fifoQueueUrl));
    }

    public function testGetQueueProperlyResolvesUrlWithSuffix(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix, $suffix = '-staging');
        $this->assertEquals($this->queueUrl . $suffix, $queue->getQueue(null));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test' . $suffix;
        $this->assertEquals($queueUrl, $queue->getQueue('test'));
    }

    public function testGetQueueProperlyResolvesFifoUrlWithSuffix(): void
    {
        $this->queueName = 'emails.fifo';
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix, $suffix = '-staging');
        $this->assertSame("{$this->prefix}emails-staging.fifo", $queue->getQueue(null));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test' . $suffix . '.fifo';
        $this->assertEquals($queueUrl, $queue->getQueue('test.fifo'));
    }

    public function testForwardedQueueNameIsUsedWhenPushing(): void
    {
        Container::setInstance($container = new Container);
        $routes = new QueueRoutes;
        $routes->forward('jobs', 'processing', 'sqs');
        $container->instance('queue.routes', $routes);

        $queue = new SqsQueue($this->sqs, 'default', $this->prefix);
        $queue->setConnectionName('sqs');

        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->prefix . 'processing',
            'MessageBody' => 'payload',
        ], $this->mockedSendMessageResponseModel);

        $queue->pushRaw('payload', 'jobs');
    }

    public function testForwardedFifoQueueControlsOptionsAndDelayValidation(): void
    {
        $routes = new QueueRoutes;
        $routes->forward(['0' => 'processing.fifo', 'processing.fifo' => 'archive'], connection: 'sqs');
        Container::getInstance()->instance('queue.routes', $routes);
        $queue = new SqsQueue($this->sqs, 'default', $this->prefix);
        $queue->setConnectionName('sqs');

        $this->assertSame($this->prefix . 'processing.fifo', $queue->getQueue(IntegerQueueName::Zero));
        $options = $queue->getQueueableOptions('job', IntegerQueueName::Zero, 'payload');
        $this->assertSame('processing.fifo', $options['MessageGroupId']);
        $this->assertArrayHasKey('MessageDeduplicationId', $options);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('SQS FIFO queues do not support per-message delays.');

        $queue->later(10, 'job', '', IntegerQueueName::Zero);
    }

    public function testGetQueueEnsuresTheQueueIsOnlySuffixedOnce(): void
    {
        $queue = new SqsQueue($this->sqs, "{$this->queueName}-staging", $this->prefix, $suffix = '-staging');
        $this->assertEquals($this->queueUrl . $suffix, $queue->getQueue(null));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test' . $suffix;
        $this->assertEquals($queueUrl, $queue->getQueue('test-staging'));
    }

    public function testGetFifoQueueEnsuresTheQueueIsOnlySuffixedOnce(): void
    {
        $queue = new SqsQueue($this->sqs, "{$this->queueName}-staging.fifo", $this->prefix, $suffix = '-staging');
        $this->assertSame("{$this->prefix}{$this->queueName}{$suffix}.fifo", $queue->getQueue(null));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test' . $suffix . '.fifo';
        $this->assertEquals($queueUrl, $queue->getQueue('test-staging.fifo'));
    }

    public function testPushProperlyPushesJobObjectOntoSqs(): void
    {
        $job = new FakeSqsJob;

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', ['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->isPayloadOf(FakeSqsJob::class)], $this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->received('bound')->with('events')->times(3);
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqs(): void
    {
        // Job will not be dispatched until the PendingDispatch object is destroyed.
        $pendingDispatch = FakeSqsJob::dispatch();

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', ['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->isPayloadOf(FakeSqsJob::class)], $this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container->instance(), fn (): SqsQueue => $queue);
        app()->instance(DispatcherContract::class, $dispatcher);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->received('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobObjectOntoSqsFairQueue(): void
    {
        $job = (new FakeSqsJob)->onGroup($this->mockedMessageGroupId);

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', ['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->isPayloadOf(FakeSqsJob::class), 'MessageGroupId' => $this->mockedMessageGroupId], $this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $payload = json_decode($this->handler->getLastCommand()['MessageBody'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($this->mockedMessageGroupId, unserialize($payload['data']['command'])->messageGroup);
        $container->received('bound')->with('events')->times(3);
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqsFairQueue(): void
    {
        $pendingDispatch = FakeSqsJob::dispatch()->onGroup($this->mockedMessageGroupId);

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', ['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->isPayloadOf(FakeSqsJob::class), 'MessageGroupId' => $this->mockedMessageGroupId], $this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container->instance(), fn (): SqsQueue => $queue);
        app()->instance(DispatcherContract::class, $dispatcher);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->received('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobStringOntoSqsFifoQueue(): void
    {
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));

        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->isPayloadOf($this->mockedJob),
            'MessageGroupId' => $this->fifoQueueName,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ], $this->mockedSendMessageResponseModel);
        $id = $queue->push($this->mockedJob, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->received('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueue(): void
    {
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));

        $job = (new FakeSqsJob)->onGroup($this->mockedMessageGroupId);

        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->isPayloadOf(FakeSqsJob::class),
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ], $this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->received('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobObjectWithoutMessageGroupOntoSqsFifoQueue(): void
    {
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));

        $job = new FakeSqsJob;

        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->isPayloadOf(FakeSqsJob::class),
            'MessageGroupId' => $this->fifoQueueName,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ], $this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->received('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobObjectWithEmptyMessageGroupOntoSqsFifoQueue(): void
    {
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));

        $calls = 0;
        FakeSqsJobWithMessageGroup::createMessageGroupsUsing(function () use (&$calls): string {
            ++$calls;

            return '';
        });

        $job = new FakeSqsJobWithMessageGroup;

        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->isPayloadOf(FakeSqsJobWithMessageGroup::class),
            'MessageGroupId' => $this->fifoQueueName,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ], $this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $this->assertSame(1, $calls);
        $container->received('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueueWithMessageGroupMethod(): void
    {
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));

        $calls = 0;
        FakeSqsJobWithMessageGroup::createMessageGroupsUsing(function () use (&$calls): string {
            ++$calls;

            return $this->mockedMessageGroupId;
        });
        $job = new FakeSqsJobWithMessageGroup;

        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->isPayloadOf(FakeSqsJobWithMessageGroup::class),
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ], $this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $this->assertSame(1, $calls);
        $container->received('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueueWithMessageGroupPropertyOverridingMethod(): void
    {
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));

        // Ensure the messageGroup method is not called when a messageGroup property is provided.
        FakeSqsJobWithMessageGroup::createMessageGroupsUsing(function (): never {
            $this->fail('The messageGroup method should not be called when a message group is provided.');
        });

        $job = (new FakeSqsJobWithMessageGroup)->onGroup($this->mockedMessageGroupId);

        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->isPayloadOf(FakeSqsJobWithMessageGroup::class),
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ], $this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->received('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueueWithDeduplicationId(): void
    {
        $calls = [];
        FakeSqsJobWithDeduplication::createDeduplicationIdsUsing(function (string $payload, string $queue) use (&$calls): string {
            $calls[] = [$payload, $queue];

            return $this->mockedDeduplicationId;
        });

        $job = (new FakeSqsJobWithDeduplication)->onGroup($this->mockedMessageGroupId);

        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->isPayloadOf(FakeSqsJobWithDeduplication::class),
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ], $this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $this->assertCount(1, $calls);
        $this->assertTrue($this->isPayloadOf(FakeSqsJobWithDeduplication::class)($calls[0][0]));
        $this->assertSame($this->fifoQueueName, $calls[0][1]);
        $container->received('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueueWithDeduplicator(): void
    {
        // Ensure the deduplicationId method is not called when a deduplicator callback is provided.
        FakeSqsJobWithDeduplication::createDeduplicationIdsUsing(function (): never {
            $this->fail('The deduplicationId method should not be called when a deduplicator callback is provided.');
        });

        // Can't reference the test case in a serialized closure.
        $deduplicationId = $this->mockedDeduplicationId;
        $calls = [];

        $job = (new FakeSqsJobWithDeduplication)->onGroup($this->mockedMessageGroupId)->withDeduplicator(
            static function (string $payload, string $queue) use (&$calls, $deduplicationId): string {
                $calls[] = [$payload, $queue];

                return $deduplicationId;
            }
        );

        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->isPayloadOf(FakeSqsJobWithDeduplication::class),
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ], $this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $this->assertSame([[$this->handler->getLastCommand()['MessageBody'], $this->fifoQueueName]], $calls);
        $container->received('bound')->with('events')->times(3);
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqsFifoQueue(): void
    {
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));

        $pendingDispatch = FakeSqsJob::dispatch()->onGroup($this->mockedMessageGroupId);

        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->isPayloadOf(FakeSqsJob::class),
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ], $this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container->instance(), fn (): SqsQueue => $queue);
        app()->instance(DispatcherContract::class, $dispatcher);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->received('bound')->with('events')->times(3);
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqsFifoQueueWithDeduplicationId(): void
    {
        FakeSqsJobWithDeduplication::createDeduplicationIdsUsing(fn (string $payload, string $queue): string => $this->mockedDeduplicationId);

        $pendingDispatch = FakeSqsJobWithDeduplication::dispatch()->onGroup($this->mockedMessageGroupId);

        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->isPayloadOf(FakeSqsJobWithDeduplication::class),
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ], $this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container->instance(), fn (): SqsQueue => $queue);
        app()->instance(DispatcherContract::class, $dispatcher);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->received('bound')->with('events')->times(3);
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqsFifoQueueWithDeduplicator(): void
    {
        FakeSqsJobWithDeduplication::createDeduplicationIdsUsing(function (string $payload, string $queue): never {
            $this->fail('The deduplicationId method should not be called when a deduplicator callback is provided.');
        });

        // Can't reference the test case in a serialized closure.
        $deduplicationId = $this->mockedDeduplicationId;
        $calls = [];

        $pendingDispatch = FakeSqsJobWithDeduplication::dispatch()->onGroup($this->mockedMessageGroupId)->withDeduplicator(
            static function (string $payload, string $queue) use (&$calls, $deduplicationId): string {
                $calls[] = [$payload, $queue];

                return $deduplicationId;
            }
        );

        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->isPayloadOf(FakeSqsJobWithDeduplication::class),
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ], $this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container->instance(), fn (): SqsQueue => $queue);
        app()->instance(DispatcherContract::class, $dispatcher);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $this->assertSame([[$this->handler->getLastCommand()['MessageBody'], $this->fifoQueueName]], $calls);
        $container->received('bound')->with('events')->times(3);
    }

    public function testJobObjectCanBeSerializedOntoSqsFifoQueueWithDeduplicator(): void
    {
        // Can't reference test case property in serialized closure.
        $deduplicationId = $this->mockedDeduplicationId;

        $pendingDispatch = FakeSqsJobWithDeduplication::dispatch()->onGroup($this->mockedMessageGroupId)->withDeduplicator(function (string $payload, string $queue) use ($deduplicationId): string {
            return $deduplicationId;
        });

        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('sendMessage', function (array $args): bool {
            $this->assertIsArray($args);
            $this->assertEqualsCanonicalizing(['QueueUrl', 'MessageBody', 'MessageGroupId', 'MessageDeduplicationId'], array_keys($args));
            $this->assertEquals($this->fifoQueueUrl, $args['QueueUrl']);
            $this->assertEquals($this->mockedMessageGroupId, $args['MessageGroupId']);
            $this->assertEquals($this->mockedDeduplicationId, $args['MessageDeduplicationId']);

            $message = json_decode($args['MessageBody'], true);
            $command = unserialize($message['data']['command'] ?? '');
            $this->assertInstanceOf(FakeSqsJobWithDeduplication::class, $command);
            $this->assertInstanceOf(SerializableClosure::class, $command->deduplicator);

            return true;
        }, $this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container->instance(), fn (): SqsQueue => $queue);
        $container->allows('make')
            ->with(DispatcherContract::class)
            ->returns($dispatcher);
        Container::setInstance($container->instance());

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->received('bound')->with('events')->times(3);
    }

    // REMOVED: Laravel's three delayed FIFO tests silently omit positive delays;
    // Hypervel rejects these unsupported delays instead of sending immediately.

    public function testDelayedPushRejectsPositiveDelayForStringJobOnSqsFifoQueue(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload'])
            ->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])
            ->getMock();
        $queue->setContainer($this->createSpyContainer()->instance());
        $queue->expects($this->never())->method('createPayload');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('SQS FIFO queues do not support per-message delays.');

        $queue->later($this->mockedDelay, $this->mockedJob, $this->mockedData, $this->fifoQueueName);
    }

    public function testDelayedPushRejectsPositiveDelayForObjectJobOnSqsFifoQueue(): void
    {
        $job = (new FakeSqsJob)->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload'])
            ->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])
            ->getMock();
        $queue->setContainer($this->createSpyContainer()->instance());
        $queue->expects($this->never())->method('createPayload');

        $this->expectException(LogicException::class);

        $queue->later($this->mockedDelay, $job, $this->mockedData, $this->fifoQueueName);
    }

    public function testDelayedPendingDispatchRejectsPositiveDelayOnSqsFifoQueue(): void
    {
        $pendingDispatch = FakeSqsJob::dispatch()->onGroup($this->mockedMessageGroupId)->delay($this->mockedDelay);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload'])
            ->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])
            ->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container->instance());
        $queue->expects($this->never())->method('createPayload');

        $dispatcher = new BusDispatcher($container->instance(), fn (): SqsQueue => $queue);
        $container->allows('make')
            ->with(DispatcherContract::class)
            ->returns($dispatcher);
        Container::setInstance($container->instance());

        $this->expectException(LogicException::class);

        unset($pendingDispatch);
    }

    #[DataProvider('nonPositiveFifoDelays')]
    public function testNonPositiveAndElapsedDelaysRemainImmediateOnSqsFifoQueue(int|string $delay): void
    {
        $delay = $delay === 'past' ? CarbonImmutable::now()->subSecond() : $delay;
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload', 'getQueue'])
            ->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])
            ->getMock();
        $queue->setContainer($this->createSpyContainer()->instance());
        $queue->expects($this->once())->method('createPayload')->with(
            $this->mockedJob,
            $this->fifoQueueName,
            $this->mockedData,
            $delay,
        )->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->fifoQueueName,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ], $this->mockedSendMessageResponseModel);

        $this->assertSame(
            $this->mockedMessageId,
            $queue->later($delay, $this->mockedJob, $this->mockedData, $this->fifoQueueName),
        );
    }

    /**
     * Provide delays that are already due for immediate delivery.
     */
    public static function nonPositiveFifoDelays(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'past date' => ['past'],
        ];
    }

    #[TestWith(['finalizing'])]
    #[TestWith(['queueing'])]
    #[TestWith(['stored'])]
    #[TestWith(['queued'])]
    public function testPushCancellationPublishesTheKnownOutcome(string $phase): void
    {
        $container = new Container;
        Container::setInstance($container);
        $events = new ConcreteEventDispatcher($container);
        $container->instance('events', $events);
        $cancellation = new RuntimeException('Listener cleanup failed.', previous: new CanceledException);
        $earlierFailure = new RuntimeException('Earlier notification failed.');
        $handler = Double::for(ExceptionHandler::class)->strict();
        if ($phase === 'queued') {
            $handler->expects('report')->with(Argument::same($earlierFailure));
        } else {
            $handler->expects('report')->never();
        }
        $container->instance(ExceptionHandler::class, $handler);
        $notified = [];
        $canceled = null;

        foreach ([JobPayloadFinalizing::class => 'finalizing', JobQueueing::class => 'queueing', JobQueued::class => 'queued', JobQueueingFailed::class => 'failed', JobQueueingCanceled::class => 'canceled'] as $eventClass => $name) {
            $events->listen($eventClass, static function (object $event) use ($name, $phase, $cancellation, &$notified, &$canceled): void {
                $notified[] = $name;
                $canceled = $event instanceof JobQueueingCanceled ? $event : $canceled;
                if ($name === $phase) {
                    throw $cancellation;
                }
            });
        }

        $queue = Double::for(SqsQueue::class)->passthru(new SqsQueue($this->sqs, $this->queueName, $this->prefix));
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');

        if (in_array($phase, ['stored', 'queued'], true)) {
            $this->expectSqsCommand('sendMessage', null, $this->mockedSendMessageResponseModel);
            $queue->expects('handlePayloadPushed')->throws($phase === 'stored' ? $cancellation : $earlierFailure);
        }

        try {
            $queue->push('job');
            $this->fail('Expected cancellation to escape dispatch.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(match ($phase) {
            'finalizing' => ['finalizing', 'canceled'],
            'queueing' => ['finalizing', 'queueing', 'canceled'],
            'stored' => ['finalizing', 'queueing', 'queued'],
            'queued' => ['finalizing', 'queueing', 'queued', 'canceled'],
        }, $notified);

        if ($canceled !== null) {
            $this->assertSame($cancellation, $canceled->cancellation);
            $this->assertSame($phase === 'queued', $canceled->sendAttempted);
            $this->assertSame($phase === 'queued', $canceled->queued);
        }
    }

    public function testPushRawStoresPayloadToCacheWhenExceedingThreshold(): void
    {
        $uuid = 'test-uuid';
        $payload = json_encode([
            'uuid' => $uuid,
            'job' => 'App\Jobs\TestJob',
            'data' => str_repeat('x', SqsQueue::MAX_SQS_PAYLOAD_SIZE),
        ], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . $uuid;
        $pointer = json_encode(['@pointer' => $path], JSON_THROW_ON_ERROR);

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->with($path, $payload)->returns(true);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->with('cache')->returns($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'store' => 'database'],
        );
        $queue->setContainer($container->instance());

        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => $pointer,
        ], $this->mockedSendMessageResponseModel);

        $this->assertSame($this->mockedMessageId, $queue->pushRaw($payload, $this->queueName));
    }

    public function testPushRawDoesNotStoreToCacheWhenBelowThreshold(): void
    {
        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'App\Jobs\TestJob',
            'data' => 'small',
        ], JSON_THROW_ON_ERROR);

        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->never()->with('cache');

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'store' => 'database'],
        );
        $queue->setContainer($container->instance());

        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => $payload,
        ], $this->mockedSendMessageResponseModel);

        $this->assertSame($this->mockedMessageId, $queue->pushRaw($payload, $this->queueName));
    }

    public function testPushRawAlwaysStoresToCacheWhenAlwaysIsTrue(): void
    {
        $payload = json_encode([
            'uuid' => 'always-overflow',
            'job' => 'App\Jobs\TestJob',
            'data' => 'small',
        ], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'always-overflow';
        $pointer = json_encode(['@pointer' => $path], JSON_THROW_ON_ERROR);

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->with($path, $payload)->returns(true);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->with('cache')->returns($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container->instance());

        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => $pointer,
        ], $this->mockedSendMessageResponseModel);

        $queue->pushRaw($payload, $this->queueName);
    }

    public function testPushRawDoesNotStoreToCacheWhenNotEnabled(): void
    {
        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'App\Jobs\TestJob',
            'data' => str_repeat('x', SqsQueue::MAX_SQS_PAYLOAD_SIZE),
        ], JSON_THROW_ON_ERROR);

        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->never()->with('cache');

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer($container->instance());

        $this->expectSqsCommand('sendMessage', [
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => $payload,
        ], $this->mockedSendMessageResponseModel);

        $this->assertSame($this->mockedMessageId, $queue->pushRaw($payload, $this->queueName));
    }

    #[DataProvider('invalidOverflowUuidProvider')]
    public function testPushRawGeneratesOverflowPathForEveryNonStringOrEmptyUuid(mixed $uuid, string $invalidPath): void
    {
        $payload = json_encode([
            'uuid' => $uuid,
            'job' => 'App\Jobs\TestJob',
            'data' => 'payload',
        ], JSON_THROW_ON_ERROR);
        $path = null;

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->with(Argument::capture($path), $payload)->returns(true);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->with('cache')->returns($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container->instance());

        $this->expectSqsCommand('sendMessage', function (array $arguments) use (&$path): bool {
            $pointer = json_decode($arguments['MessageBody'], true, flags: JSON_THROW_ON_ERROR);

            return $pointer['@pointer'] === $path;
        }, $this->mockedSendMessageResponseModel);

        $queue->pushRaw($payload, $this->queueName);

        $this->assertIsString($path);
        $this->assertStringStartsWith(SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX, $path);
        $this->assertNotSame($invalidPath, $path);
    }

    /**
     * Provide UUID values that cannot identify overflow payloads.
     */
    public static function invalidOverflowUuidProvider(): array
    {
        return [
            'array' => [[], SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'Array'],
            'object' => [(object) [], SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX],
            'boolean' => [true, SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . '1'],
            'integer' => [7, SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . '7'],
            'empty string' => ['', SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX],
        ];
    }

    #[TestWith([null])]
    #[TestWith([false])]
    #[TestWith([true])]
    public function testPushRawFailsBeforeSendingWhenOverflowStorageReturnsFalse(?bool $cleanupThrows): void
    {
        $payload = json_encode(['uuid' => 'failed-write'], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'failed-write';
        $cancellation = new CanceledException;
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Delete cleanup failed.', previous: $cancellation);
        }

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->returns(false);
        if ($cleanupThrows === null) {
            $store->expects('forget')->with($path)->returns(true);
        } else {
            $store->expects('forget')->with($path)->throws($cancellation);
        }

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->with('cache')->returns($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container->instance());

        try {
            $queue->pushRaw($payload, $this->queueName);
            $this->fail('Expected the overflow write or cleanup failure.');
        } catch (Throwable $exception) {
            if ($cleanupThrows === null) {
                $this->assertInstanceOf(RuntimeException::class, $exception);
                $this->assertStringContainsString('Unable to store the SQS overflow payload', $exception->getMessage());
            } else {
                $this->assertSame($cancellation, $exception);
            }
        }
    }

    #[DataProvider('overflowCancellationProvider')]
    public function testPushRawPreservesCancellationAndCleansTheAttemptedOverflowPayload(bool $writeCleanupThrows, ?bool $deleteCleanupThrows): void
    {
        $payload = json_encode(['uuid' => 'canceled-write'], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'canceled-write';
        $cancellation = new CanceledException;
        if ($writeCleanupThrows) {
            $cancellation = new RuntimeException('write cleanup failed', previous: $cancellation);
        }
        $cleanupCancellation = new CanceledException;
        if ($deleteCleanupThrows) {
            $cleanupCancellation = new RuntimeException('delete cleanup failed', previous: $cleanupCancellation);
        }

        $handler = Double::for(ExceptionHandler::class)->strict();
        if ($deleteCleanupThrows) {
            $handler->expects('report')->with(Argument::same($cleanupCancellation));
        } else {
            $handler->expects('report')->never();
        }
        Container::getInstance()->instance(ExceptionHandler::class, $handler);

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->with($path, $payload)->throws($cancellation);
        if ($deleteCleanupThrows === null) {
            $store->expects('forget')->with($path)->returns(true);
        } else {
            $store->expects('forget')->with($path)->throws($cleanupCancellation);
        }

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->with('cache')->returns($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container->instance());

        try {
            $queue->pushRaw($payload, $this->queueName);
            $this->fail('Expected the overflow write cancellation to be rethrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    /**
     * Provide native cancellations and errors thrown while cancellation unwinds.
     */
    public static function overflowCancellationProvider(): array
    {
        return [
            'successful cleanup' => [false, null],
            'native write and delete' => [false, false],
            'chained write' => [true, false],
            'chained delete' => [false, true],
            'chained write and delete' => [true, true],
        ];
    }

    public function testPushRawRetainsOverflowPayloadWhenSqsDeliveryIsAmbiguous(): void
    {
        $payload = json_encode(['uuid' => 'ambiguous'], JSON_THROW_ON_ERROR);

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->returns(true);
        $store->expects('forget')->never();

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->with('cache')->returns($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container->instance());

        $this->expectSqsCommand('sendMessage', null, new RuntimeException('transport failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('transport failed');

        $queue->pushRaw($payload, $this->queueName);
    }

    public function testPushRawRetainsOverflowPayloadWhenSqsDeliveryIsCanceled(): void
    {
        $payload = json_encode(['uuid' => 'canceled-delivery'], JSON_THROW_ON_ERROR);
        $cancellation = new CanceledException;

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->returns(true);
        $store->expects('forget')->never();

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->with('cache')->returns($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container->instance());

        $this->expectSqsCommand('sendMessage', null, $cancellation);

        try {
            $queue->pushRaw($payload, $this->queueName);
            $this->fail('Expected the SQS delivery cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testClearFlushesOverflowStoreWhenFlushOnClearEnabled(): void
    {
        $store = new Repository(new ArrayStore);
        $store->put('foo', 'bar');

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = $this->createSpyContainer();
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->with('cache')->returns($cache);

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix, '', false, [
            'enabled' => true,
            'store' => 'database',
            'always' => false,
            'delete_after_processing' => true,
            'flush_on_clear' => true,
        ]);
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('getQueueAttributes', ['QueueUrl' => $this->queueUrl, 'AttributeNames' => [
            'ApproximateNumberOfMessages',
            'ApproximateNumberOfMessagesDelayed',
            'ApproximateNumberOfMessagesNotVisible',
        ]], new Result(['Attributes' => [
            'ApproximateNumberOfMessages' => 3,
            'ApproximateNumberOfMessagesDelayed' => 1,
            'ApproximateNumberOfMessagesNotVisible' => 1,
        ]]));
        $this->expectSqsCommand('purgeQueue', ['QueueUrl' => $this->queueUrl]);

        $this->assertSame(5, $queue->clear($this->queueName));

        $this->assertNull($store->get('foo'));
    }

    public function testClearFailsWhenTheOverflowStoreCannotBeFlushed(): void
    {
        $store = Double::for(CacheStore::class)->strict();
        $store->expects('flush')->returns(false);

        $repository = Double::for(CacheRepository::class)->strict();
        $repository->expects('getStore')->returns($store);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($repository);

        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->with('cache')->returns($cache);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'size'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => true, 'flush_on_clear' => true, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container->instance());
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $queue->expects($this->once())->method('size')->with($this->queueName)->willReturn(5);

        $this->expectSqsCommand('purgeQueue', ['QueueUrl' => $this->queueUrl], null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to clear the SQS overflow payload store.');

        $queue->clear($this->queueName);
    }

    public function testClearDoesNotFlushOverflowStoreWhenFlushOnClearDisabled(): void
    {
        $container = $this->createSpyContainer();
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->with('cache')->never();

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix, '', false, [
            'enabled' => true,
            'store' => 'database',
            'always' => false,
            'delete_after_processing' => true,
            'flush_on_clear' => false,
        ]);
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('getQueueAttributes', ['QueueUrl' => $this->queueUrl, 'AttributeNames' => [
            'ApproximateNumberOfMessages',
            'ApproximateNumberOfMessagesDelayed',
            'ApproximateNumberOfMessagesNotVisible',
        ]], new Result(['Attributes' => [
            'ApproximateNumberOfMessages' => 3,
            'ApproximateNumberOfMessagesDelayed' => 1,
            'ApproximateNumberOfMessagesNotVisible' => 1,
        ]]));
        $this->expectSqsCommand('purgeQueue', ['QueueUrl' => $this->queueUrl]);

        $this->assertSame(5, $queue->clear($this->queueName));
    }

    public function testClearDoesNotFlushOverflowStoreWhenOverflowDisabled(): void
    {
        $container = $this->createSpyContainer();
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->with('cache')->never();

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix, '', false, [
            'enabled' => false,
            'store' => 'database',
            'always' => false,
            'delete_after_processing' => true,
            'flush_on_clear' => true,
        ]);
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('getQueueAttributes', ['QueueUrl' => $this->queueUrl, 'AttributeNames' => [
            'ApproximateNumberOfMessages',
            'ApproximateNumberOfMessagesDelayed',
            'ApproximateNumberOfMessagesNotVisible',
        ]], new Result(['Attributes' => [
            'ApproximateNumberOfMessages' => 3,
            'ApproximateNumberOfMessagesDelayed' => 1,
            'ApproximateNumberOfMessagesNotVisible' => 1,
        ]]));
        $this->expectSqsCommand('purgeQueue', ['QueueUrl' => $this->queueUrl]);

        $this->assertSame(5, $queue->clear($this->queueName));
    }

    public function testClearForwardsConfiguredStoreNameToFactory(): void
    {
        $store = new Repository(new ArrayStore);
        $store->put('foo', 'bar');

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('redis')->returns($store);

        $container = $this->createSpyContainer();
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->with('cache')->returns($cache);

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix, '', false, [
            'enabled' => true,
            'store' => 'redis',
            'always' => false,
            'delete_after_processing' => true,
            'flush_on_clear' => true,
        ]);
        $queue->setContainer($container->instance());
        $this->expectSqsCommand('getQueueAttributes', ['QueueUrl' => $this->queueUrl, 'AttributeNames' => [
            'ApproximateNumberOfMessages',
            'ApproximateNumberOfMessagesDelayed',
            'ApproximateNumberOfMessagesNotVisible',
        ]], new Result(['Attributes' => [
            'ApproximateNumberOfMessages' => 3,
            'ApproximateNumberOfMessagesDelayed' => 1,
            'ApproximateNumberOfMessagesNotVisible' => 1,
        ]]));
        $this->expectSqsCommand('purgeQueue', ['QueueUrl' => $this->queueUrl]);

        $this->assertSame(5, $queue->clear($this->queueName));

        $this->assertNull($store->get('foo'));
    }

    public function testBulkRejectsDelayAttributeOnFifoBeforePayloadCreation(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload'])
            ->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->prefix])
            ->getMock();
        $queue->setContainer(new Container);
        $queue->expects($this->never())->method('createPayload');

        $this->expectException(LogicException::class);

        $queue->bulk([new SqsBulkAttributeDelayJob], 'data', $this->fifoQueueName);
    }

    public function testMixedBulkRejectsPositiveFifoDelayBeforeTransactionLookupOrPayloadCreation(): void
    {
        $immediate = new FakeSqsJob;
        $afterCommit = (new FakeSqsJob)->afterCommit()->delay(10);
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->allows('bound')->with('queue.routes')->returns(false);
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('has')->never();

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload'])
            ->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->prefix])
            ->getMock();
        $queue->setContainer($container->instance());
        $queue->expects($this->never())->method('createPayload');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('SQS FIFO queues do not support per-message delays.');
        $queue->bulk([$immediate, $afterCommit], 'data', $this->fifoQueueName);
    }

    public function testBulkSendsAllJobsInASingleBatchRequest(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer(new Container);

        $captured = null;
        $this->expectSqsCommand('sendMessageBatch', function (array $arguments) use (&$captured): bool {
            $captured = $arguments;

            return true;
        }, new Result([
            'Successful' => [
                ['Id' => '0', 'MessageId' => 'm1'],
                ['Id' => '1', 'MessageId' => 'm2'],
                ['Id' => '2', 'MessageId' => 'm3'],
            ],
            'Failed' => [],
        ]));

        $queue->bulk(['a', 'b', 'c'], 'data', $this->queueName);

        $this->assertSame($this->queueUrl, $captured['QueueUrl']);
        $this->assertSame(['0', '1', '2'], array_column($captured['Entries'], 'Id'));
        $this->assertSame(['a', 'b', 'c'], array_map(
            static fn (string $body): string => json_decode($body, true, flags: JSON_THROW_ON_ERROR)['job'],
            array_column($captured['Entries'], 'MessageBody'),
        ));
    }

    public function testBulkChunksAtTenMessagesPerBatch(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer(new Container);

        $batchSizes = [];
        $this->expectSqsCommand('sendMessageBatch', function (array $arguments) use (&$batchSizes): bool {
            $batchSizes[] = count($arguments['Entries']);

            return true;
        }, new Result(['Successful' => [], 'Failed' => []]), 2);

        $queue->bulk(array_map('strval', range(1, 15)), 'data', $this->queueName);

        $this->assertSame([10, 5], $batchSizes);
    }

    public function testBulkChunksWhenCumulativePayloadSizeExceedsLimit(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer(new Container);

        $batchSizes = [];
        $this->expectSqsCommand('sendMessageBatch', function (array $arguments) use (&$batchSizes): bool {
            $batchSizes[] = count($arguments['Entries']);

            return true;
        }, new Result(['Successful' => [], 'Failed' => []]), 2);

        $queue->bulk(['a', 'b'], str_repeat('x', (int) (SqsQueue::MAX_SQS_PAYLOAD_SIZE * 0.6)), $this->queueName);

        $this->assertSame([1, 1], $batchSizes);
    }

    public function testBulkRaisesQueueingAndQueuedEventsForEachJob(): void
    {
        $events = Double::for(EventDispatcher::class)->strict();
        $events->allows('hasListeners')->with(JobPayloadFinalizing::class)->returns(false);
        $events->allows('hasListeners')->with(JobQueueing::class)->returns(true);
        $events->allows('hasListeners')->with(JobQueued::class)->returns(true);
        $dispatched = [];
        $events->expects('dispatch')->times(4)->resolves(function (object $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $container = new Container;
        $container->instance('events', $events);

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');

        $this->expectSqsCommand('sendMessageBatch', null, function (array $args): Result {
            $successful = array_map(
                fn (array $entry, int $i): array => ['Id' => $entry['Id'], 'MessageId' => 'mid-' . $i],
                $args['Entries'],
                array_keys($args['Entries'])
            );

            return new Result(['Successful' => $successful, 'Failed' => []]);
        });

        $queue->bulk(['a', 'b'], 'data', $this->queueName);

        $queueingEvents = array_filter($dispatched, fn (object $event): bool => $event instanceof JobQueueing);
        $queuedEvents = array_filter($dispatched, fn (object $event): bool => $event instanceof JobQueued);

        $this->assertCount(2, $queueingEvents);
        $this->assertCount(2, $queuedEvents);
        $this->assertSame(['mid-0', 'mid-1'], array_map(fn (JobQueued $event): mixed => $event->id, array_values($queuedEvents)));
    }

    public function testBulkNormalizesEnumQueueNameInEvents(): void
    {
        $container = new Container;
        $events = new ConcreteEventDispatcher($container);
        $container->instance('events', $events);
        $queues = [];

        $events->listen([JobQueueing::class, JobQueued::class], function (JobQueueing|JobQueued $event) use (&$queues): void {
            $queues[] = $event->queue;
        });

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');
        $this->expectSqsCommand('sendMessageBatch', null, new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'mid-0']],
            'Failed' => [],
        ]));

        $queue->bulk(['a'], 'data', SqsQueueName::Emails);

        $this->assertSame([$this->queueName, $this->queueName], $queues);
    }

    public function testBulkHonoursPerJobDelay(): void
    {
        $jobA = new FakeSqsJob;
        $jobA->delay = 30;

        $jobB = new FakeSqsJob;

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer(new Container);

        $captured = null;

        $this->expectSqsCommand('sendMessageBatch', function (array $args) use (&$captured): bool {
            $captured = $args;

            return true;
        }, new Result(['Successful' => [], 'Failed' => []]));

        $queue->bulk([$jobA, $jobB], 'data', $this->queueName);

        $this->assertSame(30, $captured['Entries'][0]['DelaySeconds']);
        $this->assertArrayNotHasKey('DelaySeconds', $captured['Entries'][1]);
    }

    public function testBulkHonoursDelayAttribute(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['secondsUntil'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer(new Container);
        // Convert for the payload and DelaySeconds; standard queues need no FIFO preflight conversion.
        $queue->expects($this->exactly(2))->method('secondsUntil')->with(15)->willReturn(15);

        $this->expectSqsCommand('sendMessageBatch', function (array $arguments): bool {
            $entry = $arguments['Entries'][0];
            $payload = json_decode($entry['MessageBody'], true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(15, $entry['DelaySeconds']);
            $this->assertSame(15, $payload['delay']);
            $this->assertArrayNotHasKey('DelaySeconds', $arguments['Entries'][1]);

            return true;
        }, new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'm1']],
            'Failed' => [],
        ]));

        $queue->bulk([new FakeSqsJobWithDelayAttribute, new FakeSqsJob], 'data', $this->queueName);
    }

    public function testBulkThrowsWhenSqsReportsFailedEntries(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer(new Container);

        $this->expectSqsCommand('sendMessageBatch', null, function (array $args): Result {
            return new Result([
                'Successful' => [],
                'Failed' => [
                    ['Id' => $args['Entries'][0]['Id'], 'Code' => 'InternalError', 'Message' => 'oops', 'SenderFault' => false],
                ],
            ]);
        });

        try {
            $queue->bulk(['a'], 'data', $this->queueName);

            $this->fail('SqsException was not thrown.');
        } catch (SqsException $exception) {
            $this->assertSame(
                'SQS SendMessageBatch rejected [1] of [1] messages. First failure [InternalError]: oops',
                $exception->getMessage()
            );
            $this->assertSame('InternalError', $exception->getAwsErrorCode());
            $this->assertSame('oops', $exception->getAwsErrorMessage());
            $this->assertNotNull($exception->getResult());
        }
    }

    public function testBulkFinalizesPayloadsBeforeSizeBasedChunking(): void
    {
        $dispatcher = new ConcreteEventDispatcher($container = new Container);
        $container->instance('events', $dispatcher);
        $dispatcher->listen(JobPayloadFinalizing::class, static function (JobPayloadFinalizing $event): void {
            $payload = $event->payload();
            $payload['padding'] = str_repeat('x', (int) (SqsQueue::MAX_SQS_PAYLOAD_SIZE * 0.6));
            $event->payload = json_encode($payload, JSON_THROW_ON_ERROR);
        });
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->method('createPayload')->willReturnCallback(
            static fn (string $job): string => json_encode(['uuid' => $job], JSON_THROW_ON_ERROR),
        );
        $batchSizes = [];
        $this->expectSqsCommand('sendMessageBatch', function (array $arguments) use (&$batchSizes): bool {
            $batchSizes[] = count($arguments['Entries']);

            return true;
        }, new Result(['Successful' => [], 'Failed' => []]), 2);

        $queue->bulk(['a', 'b'], 'data', $this->queueName);

        $this->assertSame([1, 1], $batchSizes);
    }

    public function testQueueableOptionsPreserveZeroFifoIdentifiers(): void
    {
        $job = (new FakeSqsJob)->onGroup('0')->withDeduplicator(static fn (): string => '0');
        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);

        $this->assertSame(
            ['MessageGroupId' => '0', 'MessageDeduplicationId' => '0'],
            $queue->getQueueableOptions($job, $this->fifoQueueName, 'payload'),
        );
    }

    #[DataProvider('defaultFifoQueueNames')]
    public function testQueueableOptionsRejectsPositiveDelayForEffectiveFifoQueue(?string $queueName): void
    {
        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);

        $this->expectException(LogicException::class);

        $queue->getQueueableOptions($this->mockedJob, $queueName, 'payload', 10);
    }

    /**
     * Provide queue names that resolve to the default FIFO queue.
     */
    public static function defaultFifoQueueNames(): array
    {
        return [
            'null uses default' => [null],
            'empty uses default' => [''],
        ];
    }

    public function testBulkRaisesExactEventsForSuccessfulAndRejectedEntries(): void
    {
        $events = Double::for(EventDispatcher::class)->strict();
        $events->allows('hasListeners')->with(JobPayloadFinalizing::class)->returns(true);
        $events->allows('hasListeners')->with(JobQueueing::class)->returns(true);
        $events->allows('hasListeners')->with(JobQueued::class)->returns(true);
        $events->allows('hasListeners')->with(JobQueueingFailed::class)->returns(true);
        $dispatched = [];
        $events->allows('dispatch')->resolves(
            function (object $event) use (&$dispatched): object {
                if ($event instanceof JobPayloadFinalizing) {
                    $event->payload .= '-final';
                }

                $dispatched[] = $event;

                return $event;
            }
        );

        $container = new Container;
        $container->instance('events', $events);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->expects($this->exactly(2))->method('createPayload')->willReturnOnConsecutiveCalls('p1', 'p2');

        $this->expectSqsCommand('sendMessageBatch', static fn (array $arguments): bool => array_column($arguments['Entries'], 'MessageBody') === ['p1-final', 'p2-final'], new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'successful-id']],
            'Failed' => [['Id' => '1', 'Code' => 'InternalError', 'Message' => 'failed']],
        ]));

        $exception = null;

        try {
            $queue->bulk(['a', 'b'], 'data', $this->queueName);
            $this->fail('The partial batch failure was not thrown.');
        } catch (SqsException $actual) {
            $exception = $actual;
            $this->assertSame('InternalError', $actual->getAwsErrorCode());
        }

        $queueing = array_values(array_filter($dispatched, static fn (object $event): bool => $event instanceof JobQueueing));
        $queued = array_values(array_filter($dispatched, static fn (object $event): bool => $event instanceof JobQueued));
        $failed = array_values(array_filter($dispatched, static fn (object $event): bool => $event instanceof JobQueueingFailed));

        $this->assertCount(2, $queueing);
        $this->assertCount(1, $queued);
        $this->assertCount(1, $failed);
        $this->assertSame('successful-id', $queued[0]->id);
        $this->assertSame('p1-final', $queued[0]->payload);
        $this->assertSame('b', $failed[0]->job);
        $this->assertSame('p2-final', $failed[0]->payload);
        $this->assertSame($exception, $failed[0]->exception);
    }

    public function testBulkCleansOnlyRejectedOverflowPointers(): void
    {
        $firstPayload = json_encode(['uuid' => 'accepted'], JSON_THROW_ON_ERROR);
        $secondPayload = json_encode(['uuid' => 'rejected'], JSON_THROW_ON_ERROR);
        $acceptedPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'accepted';
        $rejectedPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'rejected';

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->with($acceptedPath, $firstPayload)->returns(true);
        $store->expects('put')->with($rejectedPath, $secondPayload)->returns(true);
        $store->expects('forget')->with($rejectedPath)->returns(true);
        $store->expects('forget')->never()->with($acceptedPath);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = new Container;
        $container->instance('cache', $cache);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => true, 'always' => true, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->expects($this->exactly(2))->method('createPayload')->willReturnOnConsecutiveCalls(
            $firstPayload,
            $secondPayload,
        );

        $this->expectSqsCommand('sendMessageBatch', null, new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'accepted-id']],
            'Failed' => [['Id' => '1', 'Code' => 'Rejected', 'Message' => 'invalid', 'SenderFault' => true]],
        ]));

        $this->expectException(SqsException::class);

        $queue->bulk(['a', 'b'], 'data', $this->queueName);
    }

    public function testBulkRetainsOverflowPointersForServerFaultRejections(): void
    {
        $payload = json_encode(['uuid' => 'ambiguous'], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'ambiguous';

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->with($path, $payload)->returns(true);
        $store->expects('forget')->never();

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = new Container;
        $container->instance('cache', $cache);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => true, 'always' => true, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->expects($this->once())->method('createPayload')->willReturn($payload);

        // An SDK retry may have resent an entry SQS already accepted before the final server fault.
        $this->expectSqsCommand('sendMessageBatch', null, new Result([
            'Successful' => [],
            'Failed' => [['Id' => '0', 'Code' => 'InternalError', 'Message' => 'failed', 'SenderFault' => false]],
        ]));

        $this->expectException(SqsException::class);

        $queue->bulk(['a'], 'data', $this->queueName);
    }

    public function testBulkCleansEarlierWritesWhenAWriteFailsBeforeSending(): void
    {
        $firstPayload = json_encode(['uuid' => 'job-1'], JSON_THROW_ON_ERROR);
        $secondPayload = json_encode(['uuid' => 'job-2'], JSON_THROW_ON_ERROR);
        $firstPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'job-1';
        $secondPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'job-2';

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->with($firstPath, $firstPayload)->ordered()->returns(true);
        $store->expects('put')->with($secondPath, $secondPayload)->ordered()->returns(false);
        $store->expects('forget')->with($firstPath)->ordered()->returns(true);
        $store->expects('forget')->with($secondPath)->ordered()->returns(true);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $events = Double::for(EventDispatcher::class)->strict();
        $events->allows('hasListeners')->with(JobPayloadFinalizing::class)->returns(false);
        $events->allows('hasListeners')->with(JobQueueing::class)->returns(true);
        $events->allows('hasListeners')->with(JobQueueingFailed::class)->returns(true);
        $dispatched = [];
        $events->allows('dispatch')->resolves(
            static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            }
        );

        $container = new Container;
        $container->instance('cache', $cache);
        $container->instance('events', $events);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => true, 'always' => true, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->method('createPayload')->willReturnCallback(
            static fn (string $job): string => json_encode(['uuid' => "job-{$job}"], JSON_THROW_ON_ERROR),
        );

        try {
            $queue->bulk(array_map('strval', range(1, 11)), 'data', $this->queueName);
            $this->fail('Expected overflow storage to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Unable to store the SQS overflow payload', $exception->getMessage());
        }

        $queueing = array_values(array_filter($dispatched, static fn (object $event): bool => $event instanceof JobQueueing));
        $failed = array_values(array_filter($dispatched, static fn (object $event): bool => $event instanceof JobQueueingFailed));

        $this->assertSame(array_map('strval', range(1, 10)), array_column($queueing, 'job'));
        $this->assertSame(array_map('strval', range(1, 11)), array_column($failed, 'job'));
        $this->assertSame($failed[0]->exception, $failed[10]->exception);
    }

    #[DataProvider('overflowCancellationProvider')]
    public function testBulkPreservesWriteCancellationWhileDrainingAttemptedPointers(bool $writeCleanupThrows, ?bool $deleteCleanupThrows): void
    {
        $firstPayload = json_encode(['uuid' => 'first'], JSON_THROW_ON_ERROR);
        $secondPayload = json_encode(['uuid' => 'second'], JSON_THROW_ON_ERROR);
        $firstPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'first';
        $secondPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'second';
        $cancellation = new CanceledException('write canceled');
        $cleanupCancellation = new CanceledException('cleanup canceled');
        if ($writeCleanupThrows) {
            $cancellation = new RuntimeException('write cleanup failed', previous: $cancellation);
        }
        if ($deleteCleanupThrows) {
            $cleanupCancellation = new RuntimeException('delete cleanup failed', previous: $cleanupCancellation);
        }

        $handler = Double::for(ExceptionHandler::class)->strict();
        if ($deleteCleanupThrows) {
            $handler->expects('report')->with(Argument::same($cleanupCancellation));
        } else {
            $handler->expects('report')->never();
        }
        Container::getInstance()->instance(ExceptionHandler::class, $handler);

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->with($firstPath, $firstPayload)->ordered()->returns(true);
        $store->expects('put')->with($secondPath, $secondPayload)->ordered()->throws($cancellation);
        if ($deleteCleanupThrows === null) {
            $store->expects('forget')->with($firstPath)->ordered()->returns(true);
        } else {
            $store->expects('forget')->with($firstPath)->ordered()->throws($cleanupCancellation);
        }
        $store->expects('forget')->with($secondPath)->ordered()->returns(true);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $events = Double::for(EventDispatcher::class)->strict();
        $events->allows('hasListeners')->with(JobPayloadFinalizing::class)->returns(false);
        $events->allows('hasListeners')->with(JobQueueing::class)->returns(true);
        $events->expects('hasListeners')->with(JobQueueingFailed::class)->never();
        $events->allows('hasListeners')->with(JobQueueingCanceled::class)->returns(true);
        $dispatched = [];
        $events->allows('dispatch')->resolves(
            static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            }
        );

        $container = new Container;
        $container->instance('cache', $cache);
        $container->instance('events', $events);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => true, 'always' => true, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->expects($this->exactly(2))->method('createPayload')->willReturnOnConsecutiveCalls(
            $firstPayload,
            $secondPayload,
        );

        try {
            $queue->bulk(['a', 'b'], 'data', $this->queueName);
            $this->fail('Expected the overflow write cancellation to be rethrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(
            ['a', 'b'],
            array_column(array_filter($dispatched, static fn (object $event): bool => $event instanceof JobQueueing), 'job'),
        );
        $canceled = array_values(array_filter($dispatched, static fn (object $event): bool => $event instanceof JobQueueingCanceled));
        $this->assertSame(['a', 'b'], array_column($canceled, 'job'));
        $this->assertSame([$cancellation, $cancellation], array_column($canceled, 'cancellation'));
        $this->assertSame([true, true], array_column($canceled, 'sendAttempted'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testBulkCleanupCancellationSupersedesAnOrdinaryWriteFailure(bool $cleanupThrows): void
    {
        $firstPayload = json_encode(['uuid' => 'first'], JSON_THROW_ON_ERROR);
        $secondPayload = json_encode(['uuid' => 'second'], JSON_THROW_ON_ERROR);
        $firstPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'first';
        $secondPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'second';
        $cleanupCancellation = new CanceledException;
        if ($cleanupThrows) {
            $cleanupCancellation = new RuntimeException('delete cleanup failed', previous: $cleanupCancellation);
        }

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->with($firstPath, $firstPayload)->ordered()->returns(true);
        $store->expects('put')->with($secondPath, $secondPayload)->ordered()->returns(false);
        $store->expects('forget')->with($firstPath)->ordered()->throws($cleanupCancellation);
        $store->expects('forget')->with($secondPath)->ordered()->returns(true);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = new Container;
        $container->instance('cache', $cache);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => true, 'always' => true, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->expects($this->exactly(2))->method('createPayload')->willReturnOnConsecutiveCalls(
            $firstPayload,
            $secondPayload,
        );

        try {
            $queue->bulk(['a', 'b'], 'data', $this->queueName);
            $this->fail('Expected cleanup cancellation to supersede the write failure.');
        } catch (Throwable $exception) {
            $this->assertSame($cleanupCancellation, $exception);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testBulkRetainsPointersAndPublishesCancellationInsteadOfFailureWhenSqsDeliveryIsCanceled(bool $cleanupThrows): void
    {
        $payload = json_encode(['uuid' => 'canceled-batch'], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'canceled-batch';
        $cancellation = new CanceledException;
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('transport cleanup failed', previous: $cancellation);
        }

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->with($path, $payload)->returns(true);
        $store->expects('forget')->never();

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $events = Double::for(EventDispatcher::class)->strict();
        $events->allows('hasListeners')->with(JobPayloadFinalizing::class)->returns(false);
        $events->allows('hasListeners')->with(JobQueueing::class)->returns(true);
        $events->expects('hasListeners')->with(JobQueueingFailed::class)->never();
        $events->allows('hasListeners')->with(JobQueueingCanceled::class)->returns(true);
        $dispatched = [];
        $events->allows('dispatch')->resolves(
            static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            }
        );

        $container = new Container;
        $container->instance('cache', $cache);
        $container->instance('events', $events);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => true, 'always' => true, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->expects($this->once())->method('createPayload')->willReturn($payload);

        $this->expectSqsCommand('sendMessageBatch', null, $cancellation);

        try {
            $queue->bulk(['a'], 'data', $this->queueName);
            $this->fail('Expected the SQS delivery cancellation to be rethrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertCount(1, array_filter($dispatched, static fn (object $event): bool => $event instanceof JobQueueing));
        $canceled = array_values(array_filter($dispatched, static fn (object $event): bool => $event instanceof JobQueueingCanceled));
        $this->assertCount(1, $canceled);
        $this->assertSame($cancellation, $canceled[0]->cancellation);
        $this->assertTrue($canceled[0]->sendAttempted);
        $this->assertFalse($canceled[0]->queued);
    }

    public function testBulkCleansOnlyTheCurrentChunkWhenItsOverflowWriteIsCanceled(): void
    {
        $cancellation = new CanceledException;
        $currentPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'job-11';
        $writes = 0;

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->times(11)->resolves(
            function () use (&$writes, $cancellation): bool {
                if (++$writes === 11) {
                    throw $cancellation;
                }

                return true;
            }
        );
        $store->expects('forget')->with($currentPath)->returns(true);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $container = new Container;
        $container->instance('cache', $cache);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => true, 'always' => true, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->method('createPayload')->willReturnCallback(
            static fn (string $job): string => json_encode(['uuid' => "job-{$job}"], JSON_THROW_ON_ERROR)
        );

        $this->expectSqsCommand('sendMessageBatch', null, new Result(['Successful' => [], 'Failed' => []]));

        try {
            $queue->bulk(array_map('strval', range(1, 11)), 'data', $this->queueName);
            $this->fail('Expected the second chunk write cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testBulkPreservesQueueingListenerCancellationBeforeWritingOverflowPayloads(): void
    {
        $payload = json_encode(['uuid' => 'listener-canceled'], JSON_THROW_ON_ERROR);
        $cancellation = new CanceledException;

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->never();
        $store->expects('forget')->never();

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $events = Double::for(EventDispatcher::class)->strict();
        $events->expects('hasListeners')->with(JobPayloadFinalizing::class)->returns(false);
        $events->expects('hasListeners')->with(JobQueueing::class)->returns(true);
        $events->expects('dispatch')->with(Argument::type(JobQueueing::class))->throws($cancellation);
        $events->expects('hasListeners')->with(JobQueueingCanceled::class)->returns(true);
        $events->expects('dispatch')->with(Argument::satisfies(
            static fn (object $event): bool => $event instanceof JobQueueingCanceled
                && $event->cancellation === $cancellation
                && ! $event->sendAttempted,
        ));

        $container = new Container;
        $container->instance('cache', $cache);
        $container->instance('events', $events);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => true, 'always' => true, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->expects($this->once())->method('createPayload')->willReturn($payload);

        try {
            $queue->bulk(['a'], 'data', $this->queueName);
            $this->fail('Expected the queueing listener cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testBulkRetainsAmbiguousChunkPointersAndNeverWritesLaterChunks(): void
    {
        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->times(10)->returns(true);
        $store->expects('forget')->never();

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $events = Double::for(EventDispatcher::class)->strict();
        $events->allows('hasListeners')->with(JobPayloadFinalizing::class)->returns(false);
        $events->allows('hasListeners')->with(JobQueueing::class)->returns(true);
        $events->allows('hasListeners')->with(JobQueueingFailed::class)->returns(true);
        $dispatched = [];
        $events->allows('dispatch')->resolves(
            static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            }
        );

        $container = new Container;
        $container->instance('cache', $cache);
        $container->instance('events', $events);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => true, 'always' => true, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->method('createPayload')->willReturnCallback(
            static fn (string $job): string => json_encode(['uuid' => "job-{$job}"], JSON_THROW_ON_ERROR)
        );

        $this->expectSqsCommand('sendMessageBatch', null, new RuntimeException('transport failed'));

        try {
            $queue->bulk(array_map('strval', range(1, 11)), 'data', $this->queueName);
            $this->fail('Expected the SQS request to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('transport failed', $exception->getMessage());
        }

        $queueing = array_values(array_filter($dispatched, static fn (object $event): bool => $event instanceof JobQueueing));
        $failed = array_values(array_filter($dispatched, static fn (object $event): bool => $event instanceof JobQueueingFailed));

        $this->assertSame(array_map('strval', range(1, 10)), array_column($queueing, 'job'));
        $this->assertSame(array_map('strval', range(1, 11)), array_column($failed, 'job'));
        $this->assertSame($failed[0]->exception, $failed[10]->exception);
    }

    public function testBulkSendsFifoBatchesSequentiallyUsingTheQueueNameForMessageGroups(): void
    {
        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $queue->setContainer(new Container);

        $captured = [];

        $this->expectSqsCommand('sendMessageBatch', function (array $args) use (&$captured): bool {
            $captured[] = $args;

            return true;
        }, new Result(['Successful' => [], 'Failed' => []]), 2);

        $queue->bulk(array_map('strval', range(1, 15)), 'data', $this->fifoQueueName);

        $this->assertSame([10, 5], array_map(fn (array $args): int => count($args['Entries']), $captured));
        $this->assertSame($this->fifoQueueUrl, $captured[0]['QueueUrl']);
        $this->assertSame($this->fifoQueueName, $captured[0]['Entries'][0]['MessageGroupId']);
        $this->assertNotEmpty($captured[0]['Entries'][0]['MessageDeduplicationId']);
    }

    public function testBulkStopsSendingFifoBatchesAfterAFailedRequest(): void
    {
        $queue = new SqsQueue($this->sqs, $this->fifoQueueName, $this->prefix);
        $queue->setContainer(new Container);

        // Only the first chunk is attempted; its exception propagates untouched and later chunks are not sent.
        $this->expectSqsCommand('sendMessageBatch', null, new RuntimeException('SQS is down'));

        $this->expectExceptionObject(new RuntimeException('SQS is down'));

        $queue->bulk(array_map('strval', range(1, 15)), 'data', $this->fifoQueueName);
    }

    public function testBulkDefersAfterCommitJobsUntilTheTransactionCommits(): void
    {
        $jobA = (new FakeSqsJob)->afterCommit();
        $jobB = (new FakeSqsJob)->afterCommit();
        $transactions = new DatabaseTransactionsManager;
        $transactions->begin('default', 1);

        $container = new Container;
        $container->instance('db.transactions', $transactions);

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer($container);

        $sent = false;
        $this->expectSqsCommand('sendMessageBatch', null, function () use (&$sent): Result {
            $sent = true;

            return new Result(['Successful' => [], 'Failed' => []]);
        });

        $queue->bulk([$jobA, $jobB], 'data', $this->queueName);

        $this->assertFalse($sent);

        $transactions->commit('default', 1, 0);

        $this->assertTrue($sent);
    }

    public function testBulkRegistersRollbackCallbacksForUniqueAfterCommitJobs(): void
    {
        $job = new SqsQueueTestUniqueJob;
        $job->afterCommit = true;
        DispatchLockContext::registerUnique($job, Double::for(CacheRepository::class)->strict(), null, 'unique-key', 'owner');

        $transactions = Double::for(DatabaseTransactionsManager::class)->passthru(new DatabaseTransactionsManager);
        $transactions->begin('default', 1);
        $transactions->expects('addCallbackForRollback');
        $transactions->expects('addCallback');

        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $container->instance(EventDispatcher::class, new ConcreteEventDispatcher($container));
        Container::setInstance($container);

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer($container);

        $queue->bulk([$job], 'data', $this->queueName);
    }

    public function testBulkComputesQueueableOptionsBeforeApplyingOverflow(): void
    {
        $job = (new FakeSqsJob)->onGroup('0')->withDeduplicator(
            static fn (string $payload): string => 'dedupe-' . $payload
        );
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . $this->mockedDeduplicationId;
        $finalPayload = null;

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('put')->with($path, Argument::capture($finalPayload))->returns(true);

        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);

        $dispatcher = new ConcreteEventDispatcher($container = new Container);
        $container->instance('cache', $cache);
        $container->instance('events', $dispatcher);
        $dispatcher->listen(JobPayloadFinalizing::class, static function (JobPayloadFinalizing $event): void {
            $payload = $event->payload();
            $payload['telemetry'] = 'final';
            $event->payload = json_encode($payload, JSON_THROW_ON_ERROR);
        });

        $queue = new SqsQueue(
            $this->sqs,
            $this->fifoQueueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');

        $captured = null;
        $this->expectSqsCommand('sendMessageBatch', function (array $arguments) use (&$captured): bool {
            $captured = $arguments;

            return true;
        }, new Result(['Successful' => [], 'Failed' => []]));

        $queue->bulk([$job], 'data', $this->fifoQueueName);

        $this->assertTrue($this->isPayloadOf(FakeSqsJob::class)($finalPayload));
        $this->assertSame('final', json_decode($finalPayload, true, flags: JSON_THROW_ON_ERROR)['telemetry']);
        $this->assertSame('0', $captured['Entries'][0]['MessageGroupId']);
        $this->assertSame('dedupe-' . $finalPayload, $captured['Entries'][0]['MessageDeduplicationId']);
        $this->assertSame(
            json_encode(['@pointer' => $path], JSON_THROW_ON_ERROR),
            $captured['Entries'][0]['MessageBody']
        );
    }

    public function testBulkFiresQueuedEventsForSuccessfulChunksWhenAnotherChunkFails(): void
    {
        $events = Double::for(EventDispatcher::class)->strict();
        $events->allows('hasListeners')->with(JobPayloadFinalizing::class)->returns(false);
        $events->allows('hasListeners')->with(JobQueueing::class)->returns(true);
        $events->allows('hasListeners')->with(JobQueued::class)->returns(true);
        $events->allows('hasListeners')->with(JobQueueingFailed::class)->returns(false);
        $dispatched = [];
        $events->expects('dispatch')->times(25)->resolves(function (object $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $container = new Container;
        $container->instance('events', $events);

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');

        $calls = 0;

        $this->expectSqsCommand('sendMessageBatch', null, function (array $args) use (&$calls): Result {
            if ($calls++ === 0) {
                return new Result([
                    'Successful' => array_map(
                        fn (array $entry, int $i): array => ['Id' => $entry['Id'], 'MessageId' => 'mid-' . $i],
                        $args['Entries'],
                        array_keys($args['Entries'])
                    ),
                    'Failed' => [],
                ]);
            }

            throw new RuntimeException('chunk failed');
        }, 2);

        try {
            $queue->bulk(array_map('strval', range(1, 15)), 'data', $this->queueName);

            $this->fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('chunk failed', $exception->getMessage());
        }

        // The first chunk was queued before the second failed, so its queued events must already have fired.
        $queuedEvents = array_filter($dispatched, fn (object $event): bool => $event instanceof JobQueued);

        $this->assertCount(10, $queuedEvents);
    }

    public function testBulkRethrowsTheOriginalExceptionWhenASingleBatchRequestFails(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer(new Container);

        $this->expectSqsCommand('sendMessageBatch', null, new RuntimeException('SQS is down'));

        $this->expectExceptionObject(new RuntimeException('SQS is down'));

        $queue->bulk(['a'], 'data', $this->queueName);
    }

    public function testBulkDoesNothingWithEmptyInput(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer(new Container);

        $this->assertNull($queue->bulk([], 'data', $this->queueName));
    }

    public function testPopPassesOverflowStorageOptionsToJob(): void
    {
        $payload = json_encode(['job' => 'foo', 'data' => ['key' => 'value']], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'popped-job';
        $pointer = json_encode(['@pointer' => $path], JSON_THROW_ON_ERROR);

        $store = Double::for(CacheRepository::class)->strict();
        $store->expects('get')->with($path)->returns($payload);
        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('database')->returns($store);
        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('make')->with('cache')->returns($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );
        $queue->setContainer($container->instance());
        $queue->setConnectionName('sqs');

        $this->expectSqsCommand('receiveMessage', [
            'QueueUrl' => $this->queueUrl,
            'MessageSystemAttributeNames' => ['ApproximateReceiveCount'],
        ], new Result([
            'Messages' => [[
                'Body' => $pointer,
                'ReceiptHandle' => $this->mockedReceiptHandle,
                'MessageId' => $this->mockedMessageId,
            ]],
        ]));

        $job = $queue->pop($this->queueName);

        $this->assertInstanceOf(SqsJob::class, $job);
        $this->assertSame($payload, $job->getRawBody());
    }
}

#[Delay(9)]
class SqsBulkAttributeDelayJob
{
}

enum SqsQueueName: string
{
    case Emails = 'emails';
}

class SqsQueueTestUniqueJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
}
