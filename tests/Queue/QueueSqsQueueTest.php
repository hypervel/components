<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use Aws\Sqs\SqsClient;
use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Cache\Store as CacheStore;
use Hypervel\Contracts\Container\Container as ContainerContract;
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
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Uid\Uuid;

class QueueSqsQueueTest extends TestCase
{
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

        // Use Mockery to mock the SqsClient
        $this->sqs = m::mock(SqsClient::class);

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
     * Create the UUID returned by the test resolver.
     */
    protected function createMockedUuid(string $value): Uuid
    {
        return Uuid::fromString($value);
    }

    /**
     * Create a container spy with real service resolution.
     */
    protected function createSpyContainer(): Container
    {
        return m::spy(Container::class)->makePartial();
    }

    public function testPopProperlyPopsJobOffOfSqs(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->setContainer(m::mock(ContainerContract::class));
        $queue->setConnectionName('sqs');
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->expects('receiveMessage')->with(['QueueUrl' => $this->queueUrl, 'MessageSystemAttributeNames' => ['ApproximateReceiveCount']])->andReturn($this->mockedReceiveMessageResponseModel);
        $result = $queue->pop($this->queueName);
        $this->assertInstanceOf(SqsJob::class, $result);
    }

    public function testPopProperlyHandlesEmptyMessage(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->setContainer(m::mock(ContainerContract::class));
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->expects('receiveMessage')->with(['QueueUrl' => $this->queueUrl, 'MessageSystemAttributeNames' => ['ApproximateReceiveCount']])->andReturn($this->mockedReceiveEmptyMessageResponseModel);
        $result = $queue->pop($this->queueName);
        $this->assertNull($result);
    }

    public function testDelayedPushWithDateTimeProperlyPushesJobOntoSqs(): void
    {
        $now = CarbonImmutable::now();
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'secondsUntil', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $this->queueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('secondsUntil')->with($now->addSeconds(5))->willReturn(5);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->expects('sendMessage')->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload, 'DelaySeconds' => 5])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->later($now->addSeconds(5), $this->mockedJob, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testDelayedPushProperlyPushesJobOntoSqs(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'secondsUntil', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $this->queueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('secondsUntil')->with($this->mockedDelay)->willReturn($this->mockedDelay);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->expects('sendMessage')->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload, 'DelaySeconds' => $this->mockedDelay])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->later($this->mockedDelay, $this->mockedJob, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobOntoSqs(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $this->queueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->expects('sendMessage')->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($this->mockedJob, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    #[DataProvider('queueDefaultingDataProvider')]
    public function testPushPreservesZeroQueueAndDefaultsEmptyQueue(string $requestedQueue, string $logicalQueue): void
    {
        $queueUrl = $this->prefix . $logicalQueue;
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload', 'getQueue'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer($this->createSpyContainer());
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $logicalQueue, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($requestedQueue)->willReturn($queueUrl);
        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $queueUrl,
            'MessageBody' => $this->mockedPayload,
        ])->andReturn($this->mockedSendMessageResponseModel);

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
        $queue->setContainer($this->createSpyContainer());
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $logicalQueue, $this->mockedData, $this->mockedDelay)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($requestedQueue)->willReturn($queueUrl);
        $queue->expects($this->once())->method('secondsUntil')->with($this->mockedDelay)->willReturn($this->mockedDelay);
        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $queueUrl,
            'MessageBody' => $this->mockedPayload,
            'DelaySeconds' => $this->mockedDelay,
        ])->andReturn($this->mockedSendMessageResponseModel);

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
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);

        $this->sqs->expects('getQueueAttributes')->with([
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => [
                'ApproximateNumberOfMessages',
                'ApproximateNumberOfMessagesDelayed',
                'ApproximateNumberOfMessagesNotVisible',
            ],
        ])->andReturn(new Result([
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
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->account])
            ->getMock();
        $queue->expects($this->exactly(2))->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);

        $this->sqs->expects('getQueueAttributes')->with([
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessages'],
        ])->andReturn(new Result([
            'Attributes' => ['ApproximateNumberOfMessages' => 1],
        ]));

        $this->assertSame(1, $queue->pendingSize($this->queueName));

        $this->sqs->expects('getQueueAttributes')->with([
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessages'],
        ])->andReturn(new Result(['Attributes' => []]));

        $this->assertSame(0, $queue->pendingSize($this->queueName));
    }

    public function testDelayedSizeProperlyReadsSqsQueueDelayedSize(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->account])
            ->getMock();
        $queue->expects($this->exactly(2))->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);

        $this->sqs->expects('getQueueAttributes')->with([
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessagesDelayed'],
        ])->andReturn(new Result([
            'Attributes' => ['ApproximateNumberOfMessagesDelayed' => 2],
        ]));

        $this->assertSame(2, $queue->delayedSize($this->queueName));

        $this->sqs->expects('getQueueAttributes')->with([
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessagesDelayed'],
        ])->andReturn(new Result(['Attributes' => []]));

        $this->assertSame(0, $queue->delayedSize($this->queueName));
    }

    public function testReservedSizeProperlyReadsSqsQueueReservedSize(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->account])
            ->getMock();
        $queue->expects($this->exactly(2))->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);

        $this->sqs->expects('getQueueAttributes')->with([
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessagesNotVisible'],
        ])->andReturn(new Result([
            'Attributes' => ['ApproximateNumberOfMessagesNotVisible' => 3],
        ]));

        $this->assertSame(3, $queue->reservedSize($this->queueName));

        $this->sqs->expects('getQueueAttributes')->with([
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessagesNotVisible'],
        ])->andReturn(new Result(['Attributes' => []]));

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
        $this->assertEquals("{$this->prefix}emails-staging.fifo", $queue->getQueue(null));
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

        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->prefix . 'processing',
            'MessageBody' => 'payload',
        ])->andReturn($this->mockedSendMessageResponseModel);

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
        $this->expectExceptionMessage('SQS FIFO queues do not support per-message delays.');

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
        $this->assertEquals("{$this->prefix}{$this->queueName}{$suffix}.fifo", $queue->getQueue(null));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test' . $suffix . '.fifo';
        $this->assertEquals($queueUrl, $queue->getQueue('test-staging.fifo'));
    }

    public function testPushProperlyPushesJobObjectOntoSqs(): void
    {
        $job = new FakeSqsJob;

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($job, $this->queueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->expects('sendMessage')->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqs(): void
    {
        // Job will not be dispatched until the PendingDispatch object is destroyed.
        $pendingDispatch = FakeSqsJob::dispatch();

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($pendingDispatch->getJob(), $this->queueName, '')->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with(null)->willReturn($this->queueUrl);
        $this->sqs->expects('sendMessage')->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload])->andReturn($this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container, fn (): SqsQueue => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobObjectOntoSqsFairQueue(): void
    {
        $job = (new FakeSqsJob)->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($job, $this->queueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->expects('sendMessage')->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload, 'MessageGroupId' => $this->mockedMessageGroupId])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqsFairQueue(): void
    {
        $pendingDispatch = FakeSqsJob::dispatch()->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($pendingDispatch->getJob(), $this->queueName, '')->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with(null)->willReturn($this->queueUrl);
        $this->sqs->expects('sendMessage')->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload, 'MessageGroupId' => $this->mockedMessageGroupId])->andReturn($this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container, fn (): SqsQueue => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobStringOntoSqsFifoQueue(): void
    {
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->fifoQueueName,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($this->mockedJob, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);

        Str::createUuidsNormally();
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueue(): void
    {
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));

        $job = (new FakeSqsJob)->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($job, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);

        Str::createUuidsNormally();
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueueWithMessageGroupMethod(): void
    {
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));

        $job = $this->getMockBuilder(FakeSqsJobWithMessageGroup::class)->onlyMethods(['messageGroup'])->getMock();
        $job->expects($this->once())->method('messageGroup')->willReturn($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($job, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);

        Str::createUuidsNormally();
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueueWithMessageGroupPropertyOverridingMethod(): void
    {
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));

        $job = $this->getMockBuilder(FakeSqsJobWithMessageGroup::class)->onlyMethods(['messageGroup'])->getMock();

        // Ensure the messageGroup method is not called when a messageGroup property is provided.
        $job->expects($this->never())->method('messageGroup')->willReturn('this-should-not-be-used');
        $job->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($job, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);

        Str::createUuidsNormally();
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueueWithDeduplicationId(): void
    {
        $job = $this->getMockBuilder(FakeSqsJobWithDeduplication::class)->onlyMethods(['deduplicationId'])->getMock();
        $job->expects($this->once())->method('deduplicationId')->with($this->mockedPayload, $this->fifoQueueName)->willReturn($this->mockedDeduplicationId);
        $job->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($job, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueueWithDeduplicator(): void
    {
        $job = $this->getMockBuilder(FakeSqsJobWithDeduplication::class)->onlyMethods(['deduplicationId'])->getMock();

        // Ensure the deduplicationId method is not called when a deduplicator callback is provided.
        $job->expects($this->never())->method('deduplicationId')->willReturn('this-should-not-be-used');
        $job->onGroup($this->mockedMessageGroupId)->withDeduplicator(function (string $payload, string $queue): string {
            $this->assertEquals($this->mockedPayload, $payload);
            $this->assertEquals($this->fifoQueueName, $queue);

            return $this->mockedDeduplicationId;
        });

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($job, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqsFifoQueue(): void
    {
        Str::createUuidsUsing(fn (): Uuid => $this->createMockedUuid($this->mockedDeduplicationId));

        $pendingDispatch = FakeSqsJob::dispatch()->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($pendingDispatch->getJob(), $this->fifoQueueName, '')->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with(null)->willReturn($this->fifoQueueUrl);
        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container, fn (): SqsQueue => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->shouldHaveReceived('bound')->with('events')->times(3);

        Str::createUuidsNormally();
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqsFifoQueueWithDeduplicationId(): void
    {
        FakeSqsJobWithDeduplication::createDeduplicationIdsUsing(fn (string $payload, string $queue): string => $this->mockedDeduplicationId);

        $pendingDispatch = FakeSqsJobWithDeduplication::dispatch()->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($pendingDispatch->getJob(), $this->fifoQueueName, '')->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with(null)->willReturn($this->fifoQueueUrl);
        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container, fn (): SqsQueue => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->shouldHaveReceived('bound')->with('events')->times(3);

        FakeSqsJobWithDeduplication::createDeduplicationIdsNormally();
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqsFifoQueueWithDeduplicator(): void
    {
        FakeSqsJobWithDeduplication::createDeduplicationIdsUsing(function (string $payload, string $queue): never {
            $this->fail('The deduplicationId method should not be called when a deduplicator callback is provided.');
        });

        $pendingDispatch = FakeSqsJobWithDeduplication::dispatch()->onGroup($this->mockedMessageGroupId)->withDeduplicator(function (string $payload, string $queue): string {
            $this->assertEquals($this->mockedPayload, $payload);
            $this->assertEquals($this->fifoQueueName, $queue);

            return $this->mockedDeduplicationId;
        });

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->with($pendingDispatch->getJob(), $this->fifoQueueName, '')->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with(null)->willReturn($this->fifoQueueUrl);
        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container, fn (): SqsQueue => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->shouldHaveReceived('bound')->with('events')->times(3);

        FakeSqsJobWithDeduplication::createDeduplicationIdsNormally();
    }

    public function testJobObjectCanBeSerializedOntoSqsFifoQueueWithDeduplicator(): void
    {
        // Can't reference test case property in serialized closure.
        $deduplicationId = $this->mockedDeduplicationId;

        $pendingDispatch = FakeSqsJobWithDeduplication::dispatch()->onGroup($this->mockedMessageGroupId)->withDeduplicator(function (string $payload, string $queue) use ($deduplicationId): string {
            return $deduplicationId;
        });

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $container = $this->createSpyContainer();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('getQueue')->with(null)->willReturn($this->fifoQueueUrl);
        $this->sqs->expects('sendMessage')->withArgs(function (array $args): bool {
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
        })->andReturn($this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container, fn (): SqsQueue => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    // REMOVED: Laravel's three delayed FIFO tests silently omit positive delays;
    // Hypervel rejects these unsupported delays instead of sending immediately.

    public function testDelayedPushRejectsPositiveDelayForStringJobOnSqsFifoQueue(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload'])
            ->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])
            ->getMock();
        $queue->setContainer($this->createSpyContainer());
        $queue->expects($this->never())->method('createPayload');
        $this->sqs->shouldNotReceive('sendMessage');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SQS FIFO queues do not support per-message delays.');

        $queue->later($this->mockedDelay, $this->mockedJob, $this->mockedData, $this->fifoQueueName);
    }

    public function testDelayedPushRejectsPositiveDelayForObjectJobOnSqsFifoQueue(): void
    {
        $job = (new FakeSqsJob)->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload'])
            ->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])
            ->getMock();
        $queue->setContainer($this->createSpyContainer());
        $queue->expects($this->never())->method('createPayload');
        $this->sqs->shouldNotReceive('sendMessage');

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
        $queue->setContainer($container);
        $queue->expects($this->never())->method('createPayload');
        $this->sqs->shouldNotReceive('sendMessage');

        $dispatcher = new BusDispatcher($container, fn (): SqsQueue => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

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
        $queue->setContainer($this->createSpyContainer());
        $queue->expects($this->once())->method('createPayload')->with(
            $this->mockedJob,
            $this->fifoQueueName,
            $this->mockedData,
            $delay,
        )->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->fifoQueueName,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);

        $this->assertSame(
            $this->mockedMessageId,
            $queue->later($delay, $this->mockedJob, $this->mockedData, $this->fifoQueueName),
        );

        Str::createUuidsNormally();
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

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->with($path, $payload)->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(Container::class)->makePartial();
        $container->expects('make')->with('cache')->andReturn($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);

        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => $pointer,
        ])->andReturn($this->mockedSendMessageResponseModel);

        $this->assertSame($this->mockedMessageId, $queue->pushRaw($payload, $this->queueName));
    }

    public function testPushRawDoesNotStoreToCacheWhenBelowThreshold(): void
    {
        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'App\Jobs\TestJob',
            'data' => 'small',
        ], JSON_THROW_ON_ERROR);

        $container = m::mock(Container::class)->makePartial();
        $container->shouldNotReceive('make')->with('cache');

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);

        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => $payload,
        ])->andReturn($this->mockedSendMessageResponseModel);

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

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->with($path, $payload)->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(Container::class)->makePartial();
        $container->expects('make')->with('cache')->andReturn($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);

        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => $pointer,
        ])->andReturn($this->mockedSendMessageResponseModel);

        $queue->pushRaw($payload, $this->queueName);
    }

    public function testPushRawDoesNotStoreToCacheWhenNotEnabled(): void
    {
        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'App\Jobs\TestJob',
            'data' => str_repeat('x', SqsQueue::MAX_SQS_PAYLOAD_SIZE),
        ], JSON_THROW_ON_ERROR);

        $container = m::mock(Container::class)->makePartial();
        $container->shouldNotReceive('make')->with('cache');

        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer($container);

        $this->sqs->expects('sendMessage')->with([
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => $payload,
        ])->andReturn($this->mockedSendMessageResponseModel);

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

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->withArgs(
            function (string $candidate, string $stored) use (&$path, $payload): bool {
                $path = $candidate;

                return $stored === $payload;
            }
        )->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(Container::class)->makePartial();
        $container->expects('make')->with('cache')->andReturn($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);

        $this->sqs->expects('sendMessage')->withArgs(
            function (array $arguments) use (&$path): bool {
                $pointer = json_decode($arguments['MessageBody'], true, flags: JSON_THROW_ON_ERROR);

                return $pointer['@pointer'] === $path;
            }
        )->andReturn($this->mockedSendMessageResponseModel);

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

    public function testPushRawFailsBeforeSendingWhenOverflowStorageReturnsFalse(): void
    {
        $payload = json_encode(['uuid' => 'failed-write'], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'failed-write';

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->andReturnFalse();
        $store->expects('forget')->with($path)->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(ContainerContract::class);
        $container->expects('make')->with('cache')->andReturn($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);

        $this->sqs->shouldNotReceive('sendMessage');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to store the SQS overflow payload');

        $queue->pushRaw($payload, $this->queueName);
    }

    public function testPushRawPreservesCancellationAndCleansTheAttemptedOverflowPayload(): void
    {
        $payload = json_encode(['uuid' => 'canceled-write'], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'canceled-write';
        $cancellation = new CanceledException;

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->with($path, $payload)->andThrow($cancellation);
        $store->expects('forget')->with($path)->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(ContainerContract::class);
        $container->expects('make')->with('cache')->andReturn($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);

        $this->sqs->shouldNotReceive('sendMessage');

        try {
            $queue->pushRaw($payload, $this->queueName);
            $this->fail('Expected the overflow write cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testPushRawRetainsOverflowPayloadWhenSqsDeliveryIsAmbiguous(): void
    {
        $payload = json_encode(['uuid' => 'ambiguous'], JSON_THROW_ON_ERROR);

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->andReturnTrue();
        $store->shouldNotReceive('forget');

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(Container::class)->makePartial();
        $container->expects('make')->with('cache')->andReturn($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);

        $this->sqs->expects('sendMessage')->andThrow(new RuntimeException('transport failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transport failed');

        $queue->pushRaw($payload, $this->queueName);
    }

    public function testPushRawRetainsOverflowPayloadWhenSqsDeliveryIsCanceled(): void
    {
        $payload = json_encode(['uuid' => 'canceled-delivery'], JSON_THROW_ON_ERROR);
        $cancellation = new CanceledException;

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->andReturnTrue();
        $store->shouldNotReceive('forget');

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $container = m::mock(Container::class)->makePartial();
        $container->expects('make')->with('cache')->andReturn($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);

        $this->sqs->expects('sendMessage')->andThrow($cancellation);

        try {
            $queue->pushRaw($payload, $this->queueName);
            $this->fail('Expected the SQS delivery cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testClearFlushesOverflowStoreWhenFlushOnClearEnabled(): void
    {
        $store = m::mock(CacheStore::class);
        $store->expects('flush')->andReturnTrue();

        $repository = m::mock(CacheRepository::class);
        $repository->expects('getStore')->andReturn($store);

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($repository);

        $container = m::mock(ContainerContract::class);
        $container->expects('make')->with('cache')->andReturn($cache);

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
        $queue->setContainer($container);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $queue->expects($this->once())->method('size')->with($this->queueName)->willReturn(5);

        $this->sqs->expects('purgeQueue')->with(['QueueUrl' => $this->queueUrl]);

        $this->assertSame(5, $queue->clear($this->queueName));
    }

    public function testClearFailsWhenTheOverflowStoreCannotBeFlushed(): void
    {
        $store = m::mock(CacheStore::class);
        $store->expects('flush')->andReturnFalse();

        $repository = m::mock(CacheRepository::class);
        $repository->expects('getStore')->andReturn($store);

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($repository);

        $container = m::mock(ContainerContract::class);
        $container->expects('make')->with('cache')->andReturn($cache);

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
        $queue->setContainer($container);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $queue->expects($this->once())->method('size')->with($this->queueName)->willReturn(5);

        $this->sqs->expects('purgeQueue')->with(['QueueUrl' => $this->queueUrl]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to clear the SQS overflow payload store.');

        $queue->clear($this->queueName);
    }

    public function testClearDoesNotFlushOverflowStoreWhenFlushOnClearDisabled(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldNotReceive('make');

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'size'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => true, 'flush_on_clear' => false, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $queue->expects($this->once())->method('size')->with($this->queueName)->willReturn(5);

        $this->sqs->expects('purgeQueue')->with(['QueueUrl' => $this->queueUrl]);

        $this->assertSame(5, $queue->clear($this->queueName));
    }

    public function testClearDoesNotFlushOverflowStoreWhenOverflowDisabled(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldNotReceive('make');

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'size'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => false, 'flush_on_clear' => true, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $queue->expects($this->once())->method('size')->with($this->queueName)->willReturn(5);

        $this->sqs->expects('purgeQueue')->with(['QueueUrl' => $this->queueUrl]);

        $this->assertSame(5, $queue->clear($this->queueName));
    }

    public function testClearDoesNotResolveOverflowStoreWhenBothFlagsAreDisabled(): void
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldNotReceive('make');

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'size'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => false, 'flush_on_clear' => false, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $queue->expects($this->once())->method('size')->with($this->queueName)->willReturn(5);

        $this->sqs->expects('purgeQueue')->with(['QueueUrl' => $this->queueUrl]);

        $this->assertSame(5, $queue->clear($this->queueName));
    }

    public function testClearForwardsConfiguredStoreNameToFactory(): void
    {
        $store = m::mock(CacheStore::class);
        $store->expects('flush')->andReturnTrue();

        $repository = m::mock(CacheRepository::class);
        $repository->expects('getStore')->andReturn($store);

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('redis')->andReturn($repository);

        $container = m::mock(ContainerContract::class);
        $container->expects('make')->with('cache')->andReturn($cache);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'size'])
            ->setConstructorArgs([
                $this->sqs,
                $this->queueName,
                $this->prefix,
                '',
                false,
                ['enabled' => true, 'flush_on_clear' => true, 'store' => 'redis'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $queue->expects($this->once())->method('size')->with($this->queueName)->willReturn(5);

        $this->sqs->expects('purgeQueue')->with(['QueueUrl' => $this->queueUrl]);

        $this->assertSame(5, $queue->clear($this->queueName));
    }

    public function testBulkRejectsDelayAttributeOnFifoBeforePayloadCreation(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload'])
            ->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->prefix])
            ->getMock();
        $queue->setContainer(new Container);
        $queue->expects($this->never())->method('createPayload');
        $this->sqs->shouldNotReceive('sendMessageBatch');

        $this->expectException(LogicException::class);

        $queue->bulk([new SqsBulkAttributeDelayJob], 'data', $this->fifoQueueName);
    }

    public function testMixedBulkRejectsPositiveFifoDelayBeforeTransactionLookupOrPayloadCreation(): void
    {
        $immediate = new FakeSqsJob;
        $afterCommit = (new FakeSqsJob)->afterCommit()->delay(10);
        $container = m::mock(ContainerContract::class);
        $container->shouldNotReceive('has');

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload'])
            ->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->prefix])
            ->getMock();
        $queue->setContainer($container);
        $queue->expects($this->never())->method('createPayload');
        $this->sqs->shouldNotReceive('sendMessageBatch');

        $this->expectException(LogicException::class);

        $queue->bulk([$immediate, $afterCommit], 'data', $this->fifoQueueName);
    }

    public function testBulkSendsAllJobsInASingleBatchRequest(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer(new Container);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $queue->expects($this->exactly(3))->method('createPayload')->willReturnOnConsecutiveCalls('p1', 'p2', 'p3');

        $captured = null;
        $this->sqs->expects('sendMessageBatch')->withArgs(
            function (array $arguments) use (&$captured): bool {
                $captured = $arguments;

                return true;
            }
        )->andReturn(new Result([
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
        $this->assertSame(['p1', 'p2', 'p3'], array_column($captured['Entries'], 'MessageBody'));
    }

    public function testBulkChunksAtTenMessagesPerBatch(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer(new Container);
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->method('createPayload')->willReturnCallback(static fn (string $job): string => "payload-{$job}");

        $batchSizes = [];
        $this->sqs->expects('sendMessageBatch')->times(2)->withArgs(
            function (array $arguments) use (&$batchSizes): bool {
                $batchSizes[] = count($arguments['Entries']);

                return true;
            }
        )->andReturn(new Result(['Successful' => [], 'Failed' => []]));

        $queue->bulk(array_map('strval', range(1, 15)), 'data', $this->queueName);

        $this->assertSame([10, 5], $batchSizes);
    }

    public function testBulkChunksWhenCumulativePayloadSizeExceedsLimit(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer(new Container);
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->method('createPayload')->willReturn(
            str_repeat('x', (int) (SqsQueue::MAX_SQS_PAYLOAD_SIZE * 0.6))
        );

        $batchSizes = [];
        $this->sqs->expects('sendMessageBatch')->times(2)->withArgs(
            function (array $arguments) use (&$batchSizes): bool {
                $batchSizes[] = count($arguments['Entries']);

                return true;
            }
        )->andReturn(new Result(['Successful' => [], 'Failed' => []]));

        $queue->bulk(['a', 'b'], 'data', $this->queueName);

        $this->assertSame([1, 1], $batchSizes);
    }

    public function testBulkRaisesQueueingAndQueuedEventsForEachJob(): void
    {
        $events = m::mock(EventDispatcher::class);
        $events->shouldReceive('hasListeners')->with(JobPayloadFinalizing::class)->andReturnFalse();
        $events->shouldReceive('hasListeners')->with(JobQueueing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->with(JobQueued::class)->andReturnTrue();
        $dispatched = [];
        $events->expects('dispatch')->times(4)->andReturnUsing(function (object $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $container = new Container;
        $container->instance('events', $events);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->method('createPayload')->willReturnCallback(fn (string $job): string => "payload-{$job}");

        $this->sqs->expects('sendMessageBatch')->andReturnUsing(function (array $args): Result {
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

    public function testBulkHonoursPerJobDelay(): void
    {
        $jobA = new FakeSqsJob;
        $jobA->delay = 30;

        $jobB = new FakeSqsJob;

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload', 'secondsUntil'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer(new Container);
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->method('createPayload')->willReturnCallback(
            fn (FakeSqsJob $job, ?string $queue, mixed $data, ?int $delay): string => 'payload-' . ($delay ?? 'none')
        );
        $queue->expects($this->once())->method('secondsUntil')->with(30)->willReturn(30);

        $captured = null;

        $this->sqs->expects('sendMessageBatch')->with(m::on(function (array $args) use (&$captured): bool {
            $captured = $args;

            return true;
        }))->andReturn(new Result(['Successful' => [], 'Failed' => []]));

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

        $this->sqs->expects('sendMessageBatch')->withArgs(
            function (array $arguments): bool {
                $entry = $arguments['Entries'][0];
                $payload = json_decode($entry['MessageBody'], true, flags: JSON_THROW_ON_ERROR);

                $this->assertSame(15, $entry['DelaySeconds']);
                $this->assertSame(15, $payload['delay']);
                $this->assertArrayNotHasKey('DelaySeconds', $arguments['Entries'][1]);

                return true;
            }
        )->andReturn(new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'm1']],
            'Failed' => [],
        ]));

        $queue->bulk([new FakeSqsJobWithDelayAttribute, new FakeSqsJob], 'data', $this->queueName);
    }

    public function testBulkThrowsWhenSqsReportsFailedEntries(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer(new Container);
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->expects($this->once())->method('createPayload')->willReturn('payload-a');

        $this->sqs->expects('sendMessageBatch')->andReturnUsing(function (array $args): Result {
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
        $this->sqs->expects('sendMessageBatch')->times(2)->withArgs(
            function (array $arguments) use (&$batchSizes): bool {
                $batchSizes[] = count($arguments['Entries']);

                return true;
            },
        )->andReturn(new Result(['Successful' => [], 'Failed' => []]));

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
        $events = m::mock(EventDispatcher::class);
        $events->shouldReceive('hasListeners')->with(JobPayloadFinalizing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->with(JobQueueing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->with(JobQueued::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->with(JobQueueingFailed::class)->andReturnTrue();
        $dispatched = [];
        $events->shouldReceive('dispatch')->andReturnUsing(
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

        $this->sqs->expects('sendMessageBatch')->withArgs(
            static fn (array $arguments): bool => array_column($arguments['Entries'], 'MessageBody') === ['p1-final', 'p2-final'],
        )->andReturn(new Result([
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

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->with($acceptedPath, $firstPayload)->andReturnTrue();
        $store->expects('put')->with($rejectedPath, $secondPayload)->andReturnTrue();
        $store->expects('forget')->with($rejectedPath)->andReturnTrue();
        $store->shouldNotReceive('forget')->with($acceptedPath);

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

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

        $this->sqs->expects('sendMessageBatch')->andReturn(new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'accepted-id']],
            'Failed' => [['Id' => '1', 'Code' => 'Rejected', 'Message' => 'invalid']],
        ]));

        $this->expectException(SqsException::class);

        $queue->bulk(['a', 'b'], 'data', $this->queueName);
    }

    public function testBulkCleansEarlierWritesWhenAWriteFailsBeforeSending(): void
    {
        $firstPayload = json_encode(['uuid' => 'job-1'], JSON_THROW_ON_ERROR);
        $secondPayload = json_encode(['uuid' => 'job-2'], JSON_THROW_ON_ERROR);
        $firstPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'job-1';
        $secondPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'job-2';

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->with($firstPath, $firstPayload)->ordered()->andReturnTrue();
        $store->expects('put')->with($secondPath, $secondPayload)->ordered()->andReturnFalse();
        $store->expects('forget')->with($firstPath)->ordered()->andReturnTrue();
        $store->expects('forget')->with($secondPath)->ordered()->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $events = m::mock(EventDispatcher::class);
        $events->shouldReceive('hasListeners')->with(JobPayloadFinalizing::class)->andReturnFalse();
        $events->shouldReceive('hasListeners')->with(JobQueueing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->with(JobQueueingFailed::class)->andReturnTrue();
        $dispatched = [];
        $events->shouldReceive('dispatch')->andReturnUsing(
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

        $this->sqs->shouldNotReceive('sendMessageBatch');

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

    public function testBulkPreservesWriteCancellationWhileDrainingAttemptedPointers(): void
    {
        $firstPayload = json_encode(['uuid' => 'first'], JSON_THROW_ON_ERROR);
        $secondPayload = json_encode(['uuid' => 'second'], JSON_THROW_ON_ERROR);
        $firstPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'first';
        $secondPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'second';
        $cancellation = new CanceledException('write canceled');
        $cleanupCancellation = new CanceledException('cleanup canceled');

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->with($firstPath, $firstPayload)->ordered()->andReturnTrue();
        $store->expects('put')->with($secondPath, $secondPayload)->ordered()->andThrow($cancellation);
        $store->expects('forget')->with($firstPath)->ordered()->andThrow($cleanupCancellation);
        $store->expects('forget')->with($secondPath)->ordered()->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $events = m::mock(EventDispatcher::class);
        $events->shouldReceive('hasListeners')->with(JobPayloadFinalizing::class)->andReturnFalse();
        $events->shouldReceive('hasListeners')->with(JobQueueing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->with(JobQueueingFailed::class)->never();
        $dispatched = [];
        $events->shouldReceive('dispatch')->andReturnUsing(
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

        $this->sqs->shouldNotReceive('sendMessageBatch');

        try {
            $queue->bulk(['a', 'b'], 'data', $this->queueName);
            $this->fail('Expected the overflow write cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(
            ['a', 'b'],
            array_column(array_filter($dispatched, static fn (object $event): bool => $event instanceof JobQueueing), 'job'),
        );
    }

    public function testBulkCleanupCancellationSupersedesAnOrdinaryWriteFailure(): void
    {
        $firstPayload = json_encode(['uuid' => 'first'], JSON_THROW_ON_ERROR);
        $secondPayload = json_encode(['uuid' => 'second'], JSON_THROW_ON_ERROR);
        $firstPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'first';
        $secondPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'second';
        $cleanupCancellation = new CanceledException;

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->with($firstPath, $firstPayload)->ordered()->andReturnTrue();
        $store->expects('put')->with($secondPath, $secondPayload)->ordered()->andReturnFalse();
        $store->expects('forget')->with($firstPath)->ordered()->andThrow($cleanupCancellation);
        $store->expects('forget')->with($secondPath)->ordered()->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

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

        $this->sqs->shouldNotReceive('sendMessageBatch');

        try {
            $queue->bulk(['a', 'b'], 'data', $this->queueName);
            $this->fail('Expected cleanup cancellation to supersede the write failure.');
        } catch (CanceledException $exception) {
            $this->assertSame($cleanupCancellation, $exception);
        }
    }

    public function testBulkRetainsPointersAndSuppressesFailureEventsWhenSqsDeliveryIsCanceled(): void
    {
        $payload = json_encode(['uuid' => 'canceled-batch'], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'canceled-batch';
        $cancellation = new CanceledException;

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->with($path, $payload)->andReturnTrue();
        $store->shouldNotReceive('forget');

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $events = m::mock(EventDispatcher::class);
        $events->shouldReceive('hasListeners')->with(JobPayloadFinalizing::class)->andReturnFalse();
        $events->shouldReceive('hasListeners')->with(JobQueueing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->with(JobQueueingFailed::class)->never();
        $dispatched = [];
        $events->shouldReceive('dispatch')->andReturnUsing(
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

        $this->sqs->expects('sendMessageBatch')->andThrow($cancellation);

        try {
            $queue->bulk(['a'], 'data', $this->queueName);
            $this->fail('Expected the SQS delivery cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertCount(1, array_filter($dispatched, static fn (object $event): bool => $event instanceof JobQueueing));
    }

    public function testBulkCleansOnlyTheCurrentChunkWhenItsOverflowWriteIsCanceled(): void
    {
        $cancellation = new CanceledException;
        $currentPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'job-11';
        $writes = 0;

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->times(11)->andReturnUsing(
            function () use (&$writes, $cancellation): bool {
                if (++$writes === 11) {
                    throw $cancellation;
                }

                return true;
            }
        );
        $store->expects('forget')->with($currentPath)->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

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

        $this->sqs->expects('sendMessageBatch')->andReturn(
            new Result(['Successful' => [], 'Failed' => []])
        );

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

        $store = m::mock(CacheRepository::class);
        $store->shouldNotReceive('put');
        $store->shouldNotReceive('forget');

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $events = m::mock(EventDispatcher::class);
        $events->expects('hasListeners')->with(JobPayloadFinalizing::class)->andReturnFalse();
        $events->expects('hasListeners')->with(JobQueueing::class)->andReturnTrue();
        $events->expects('dispatch')->with(m::type(JobQueueing::class))->andThrow($cancellation);

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

        $this->sqs->shouldNotReceive('sendMessageBatch');

        try {
            $queue->bulk(['a'], 'data', $this->queueName);
            $this->fail('Expected the queueing listener cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testBulkRetainsAmbiguousChunkPointersAndNeverWritesLaterChunks(): void
    {
        $store = m::mock(CacheRepository::class);
        $store->expects('put')->times(10)->andReturnTrue();
        $store->shouldNotReceive('forget');

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $events = m::mock(EventDispatcher::class);
        $events->shouldReceive('hasListeners')->with(JobPayloadFinalizing::class)->andReturnFalse();
        $events->shouldReceive('hasListeners')->with(JobQueueing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->with(JobQueueingFailed::class)->andReturnTrue();
        $dispatched = [];
        $events->shouldReceive('dispatch')->andReturnUsing(
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

        $this->sqs->expects('sendMessageBatch')->andThrow(new RuntimeException('transport failed'));

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
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->prefix])
            ->getMock();
        $queue->setContainer(new Container);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $queue->method('createPayload')->willReturnCallback(fn (string $job): string => "payload-{$job}");

        $captured = [];

        $this->sqs->expects('sendMessageBatch')->times(2)->with(m::on(function (array $args) use (&$captured): bool {
            $captured[] = $args;

            return true;
        }))->andReturn(new Result(['Successful' => [], 'Failed' => []]));

        $queue->bulk(array_map('strval', range(1, 15)), 'data', $this->fifoQueueName);

        $this->assertSame([10, 5], array_map(fn (array $args): int => count($args['Entries']), $captured));
        $this->assertSame($this->fifoQueueUrl, $captured[0]['QueueUrl']);
        $this->assertSame($this->fifoQueueName, $captured[0]['Entries'][0]['MessageGroupId']);
        $this->assertNotEmpty($captured[0]['Entries'][0]['MessageDeduplicationId']);
    }

    public function testBulkStopsSendingFifoBatchesAfterAFailedRequest(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->prefix])
            ->getMock();
        $queue->setContainer(new Container);
        $queue->expects($this->once())->method('getQueue')->willReturn($this->fifoQueueUrl);
        $queue->method('createPayload')->willReturnCallback(fn (string $job): string => "payload-{$job}");

        // Only the first chunk is attempted; its exception propagates untouched and later chunks are not sent.
        $this->sqs->expects('sendMessageBatch')->andThrow(new RuntimeException('SQS is down'));

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

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->expects($this->exactly(2))->method('createPayload')->willReturnOnConsecutiveCalls('p1', 'p2');

        $sent = false;
        $this->sqs->expects('sendMessageBatch')->andReturnUsing(
            function () use (&$sent): Result {
                $sent = true;

                return new Result(['Successful' => [], 'Failed' => []]);
            }
        );

        $queue->bulk([$jobA, $jobB], 'data', $this->queueName);

        $this->assertFalse($sent);

        $transactions->commit('default', 1, 0);

        $this->assertTrue($sent);
    }

    public function testBulkRegistersRollbackCallbacksForUniqueAfterCommitJobs(): void
    {
        $job = new class implements ShouldQueue, ShouldBeUnique {
            use Queueable;
        };
        $job->afterCommit = true;
        DispatchLockContext::registerUnique($job, m::mock(CacheRepository::class), null, 'unique-key', 'owner');

        $transactions = m::mock(DatabaseTransactionsManager::class)->makePartial();
        $transactions->begin('default', 1);
        $transactions->expects('addCallbackForRollback');
        $transactions->expects('addCallback');

        $container = new Container;
        $container->instance('db.transactions', $transactions);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('createPayload')->willReturn('payload-a');

        $queue->bulk([$job], 'data', $this->queueName);
    }

    public function testBulkComputesQueueableOptionsBeforeApplyingOverflow(): void
    {
        $job = (new FakeSqsJob)->onGroup('0')->withDeduplicator(
            static fn (string $payload): string => 'dedupe-' . $payload
        );
        $payload = json_encode(['uuid' => 'fifo-overflow'], JSON_THROW_ON_ERROR);
        $finalPayload = json_encode(['uuid' => 'fifo-overflow', 'telemetry' => 'final'], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'fifo-overflow';

        $store = m::mock(CacheRepository::class);
        $store->expects('put')->with($path, $finalPayload)->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);

        $dispatcher = new ConcreteEventDispatcher($container = new Container);
        $container->instance('cache', $cache);
        $container->instance('events', $dispatcher);
        $dispatcher->listen(JobPayloadFinalizing::class, static function (JobPayloadFinalizing $event): void {
            $payload = $event->payload();
            $payload['telemetry'] = 'final';
            $event->payload = json_encode($payload, JSON_THROW_ON_ERROR);
        });

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([
                $this->sqs,
                $this->fifoQueueName,
                $this->prefix,
                '',
                false,
                ['enabled' => true, 'always' => true, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');
        $queue->expects($this->once())->method('getQueue')->willReturn($this->fifoQueueUrl);
        $queue->expects($this->once())->method('createPayload')->willReturn($payload);

        $captured = null;
        $this->sqs->expects('sendMessageBatch')->withArgs(
            function (array $arguments) use (&$captured): bool {
                $captured = $arguments;

                return true;
            }
        )->andReturn(new Result(['Successful' => [], 'Failed' => []]));

        $queue->bulk([$job], 'data', $this->fifoQueueName);

        $this->assertSame('0', $captured['Entries'][0]['MessageGroupId']);
        $this->assertSame('dedupe-' . $finalPayload, $captured['Entries'][0]['MessageDeduplicationId']);
        $this->assertSame(
            json_encode(['@pointer' => $path], JSON_THROW_ON_ERROR),
            $captured['Entries'][0]['MessageBody']
        );
    }

    public function testBulkFiresQueuedEventsForSuccessfulChunksWhenAnotherChunkFails(): void
    {
        $events = m::mock(EventDispatcher::class);
        $events->shouldReceive('hasListeners')->with(JobPayloadFinalizing::class)->andReturnFalse();
        $events->shouldReceive('hasListeners')->with(JobQueueing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->with(JobQueued::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->with(JobQueueingFailed::class)->andReturnFalse();
        $dispatched = [];
        $events->expects('dispatch')->times(25)->andReturnUsing(function (object $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $container = new Container;
        $container->instance('events', $events);

        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->method('createPayload')->willReturnCallback(fn (string $job): string => "payload-{$job}");

        $calls = 0;

        $this->sqs->expects('sendMessageBatch')->times(2)->andReturnUsing(function (array $args) use (&$calls): Result {
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
        });

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
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer(new Container);
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->expects($this->once())->method('createPayload')->willReturn('payload-a');

        $this->sqs->expects('sendMessageBatch')->andThrow(new RuntimeException('SQS is down'));

        $this->expectExceptionObject(new RuntimeException('SQS is down'));

        $queue->bulk(['a'], 'data', $this->queueName);
    }

    public function testBulkDoesNothingWithEmptyInput(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer(new Container);

        $this->sqs->shouldNotReceive('sendMessageBatch');

        $this->assertNull($queue->bulk([], 'data', $this->queueName));
    }

    public function testPopPassesOverflowStorageOptionsToJob(): void
    {
        $payload = json_encode(['job' => 'foo', 'data' => ['key' => 'value']], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'popped-job';
        $pointer = json_encode(['@pointer' => $path], JSON_THROW_ON_ERROR);

        $store = m::mock(CacheRepository::class);
        $store->expects('get')->with($path)->andReturn($payload);
        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('database')->andReturn($store);
        $container = m::mock(Container::class)->makePartial();
        $container->expects('make')->with('cache')->andReturn($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'store' => 'database', 'delete_after_processing' => true],
        );
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');

        $this->sqs->expects('receiveMessage')->with([
            'QueueUrl' => $this->queueUrl,
            'MessageSystemAttributeNames' => ['ApproximateReceiveCount'],
        ])->andReturn(new Result([
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
