<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use Aws\Sqs\SqsClient;
use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Cache\Store as CacheStore;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Jobs\SqsJob;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Queue\SqsQueue;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Tests\Queue\Fixtures\FakeSqsJob;
use Hypervel\Tests\Queue\Fixtures\FakeSqsJobWithDeduplication;
use Hypervel\Tests\Queue\Fixtures\FakeSqsJobWithMessageGroup;
use Hypervel\Tests\TestCase;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

class QueueSqsQueueTest extends TestCase
{
    protected $sqs;

    protected $account;

    protected $queueName;

    protected $baseUrl;

    protected $prefix;

    protected $queueUrl;

    protected $mockedJob;

    protected $mockedData;

    protected $mockedPayload;

    protected $mockedDelay;

    protected $mockedMessageId;

    protected $mockedReceiptHandle;

    protected $mockedSendMessageResponseModel;

    protected $mockedReceiveMessageResponseModel;

    protected $mockedReceiveEmptyMessageResponseModel;

    protected $fifoQueueName;

    protected $fifoQueueUrl;

    protected $mockedMessageGroupId;

    protected $mockedDeduplicationId;

    protected $mockedQueueAttributesResponseModel;

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

    protected function createMockedUuid(string $value): Uuid
    {
        return Uuid::fromString($value);
    }

    protected function createSpyContainer(): Container
    {
        $container = m::spy(Container::class);

        $container->shouldReceive('has')
            ->with('queue.routes')
            ->andReturn(true);
        $container->shouldReceive('make')
            ->with('queue.routes')
            ->andReturn(new QueueRoutes);

        return $container;
    }

    public function testPopProperlyPopsJobOffOfSqs()
    {
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->setContainer(m::mock(ContainerContract::class));
        $queue->setConnectionName('sqs');
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->shouldReceive('receiveMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'AttributeNames' => ['ApproximateReceiveCount']])->andReturn($this->mockedReceiveMessageResponseModel);
        $result = $queue->pop($this->queueName);
        $this->assertInstanceOf(SqsJob::class, $result);
    }

    public function testPopProperlyHandlesEmptyMessage()
    {
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->setContainer(m::mock(ContainerContract::class));
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->shouldReceive('receiveMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'AttributeNames' => ['ApproximateReceiveCount']])->andReturn($this->mockedReceiveEmptyMessageResponseModel);
        $result = $queue->pop($this->queueName);
        $this->assertNull($result);
    }

    public function testDelayedPushWithDateTimeProperlyPushesJobOntoSqs(): void
    {
        $now = CarbonImmutable::now();
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'secondsUntil', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->setContainer($container = m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $this->queueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('secondsUntil')->with($now->addSeconds(5))->willReturn(5);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload, 'DelaySeconds' => 5])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->later($now->addSeconds(5), $this->mockedJob, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    public function testDelayedPushProperlyPushesJobOntoSqs()
    {
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'secondsUntil', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->setContainer($container = m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $this->queueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('secondsUntil')->with($this->mockedDelay)->willReturn($this->mockedDelay);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload, 'DelaySeconds' => $this->mockedDelay])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->later($this->mockedDelay, $this->mockedJob, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    public function testPushProperlyPushesJobOntoSqs()
    {
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->setContainer($container = m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $this->queueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($this->mockedJob, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    #[DataProvider('queueDefaultingDataProvider')]
    public function testPushPreservesZeroQueueAndDefaultsEmptyQueue(string $requestedQueue, string $logicalQueue): void
    {
        $queueUrl = $this->prefix . $logicalQueue;
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['createPayload', 'getQueue'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer(m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $logicalQueue, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($requestedQueue)->willReturn($queueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
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
        $queue->setContainer(m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $logicalQueue, $this->mockedData, $this->mockedDelay)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($requestedQueue)->willReturn($queueUrl);
        $queue->expects($this->once())->method('secondsUntil')->with($this->mockedDelay)->willReturn($this->mockedDelay);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $queueUrl,
            'MessageBody' => $this->mockedPayload,
            'DelaySeconds' => $this->mockedDelay,
        ])->andReturn($this->mockedSendMessageResponseModel);

        $this->assertSame(
            $this->mockedMessageId,
            $queue->later($this->mockedDelay, $this->mockedJob, $this->mockedData, $requestedQueue)
        );
    }

    public static function queueDefaultingDataProvider(): array
    {
        return [
            'preserves zero queue' => ['0', '0'],
            'defaults empty queue' => ['', 'emails'],
        ];
    }

    public function testSizeProperlyReadsSqsQueueSize()
    {
        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);

        $this->sqs->shouldReceive('getQueueAttributes')->once()->with([
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

        $this->sqs->shouldReceive('getQueueAttributes')->once()->with([
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessages'],
        ])->andReturn(new Result([
            'Attributes' => ['ApproximateNumberOfMessages' => 1],
        ]));

        $this->assertSame(1, $queue->pendingSize($this->queueName));

        $this->sqs->shouldReceive('getQueueAttributes')->once()->with([
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

        $this->sqs->shouldReceive('getQueueAttributes')->once()->with([
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessagesDelayed'],
        ])->andReturn(new Result([
            'Attributes' => ['ApproximateNumberOfMessagesDelayed' => 2],
        ]));

        $this->assertSame(2, $queue->delayedSize($this->queueName));

        $this->sqs->shouldReceive('getQueueAttributes')->once()->with([
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

        $this->sqs->shouldReceive('getQueueAttributes')->once()->with([
            'QueueUrl' => $this->queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessagesNotVisible'],
        ])->andReturn(new Result([
            'Attributes' => ['ApproximateNumberOfMessagesNotVisible' => 3],
        ]));

        $this->assertSame(3, $queue->reservedSize($this->queueName));

        $this->sqs->shouldReceive('getQueueAttributes')->once()->with([
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

    public function testGetQueueProperlyResolvesUrlWithPrefix()
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $this->assertEquals($this->queueUrl, $queue->getQueue(null));
        $this->assertEquals($this->queueUrl, $queue->getQueue(''));
        $this->assertEquals($this->prefix . '0', $queue->getQueue('0'));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test';
        $this->assertEquals($queueUrl, $queue->getQueue('test'));
    }

    public function testGetQueueProperlyResolvesFifoUrlWithPrefix()
    {
        $this->queueName = 'emails.fifo';
        $this->queueUrl = $this->prefix . $this->queueName;
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $this->assertEquals($this->queueUrl, $queue->getQueue(null));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test.fifo';
        $this->assertEquals($queueUrl, $queue->getQueue('test.fifo'));
    }

    public function testGetQueueProperlyResolvesUrlWithoutPrefix()
    {
        $queue = new SqsQueue($this->sqs, $this->queueUrl);
        $this->assertEquals($this->queueUrl, $queue->getQueue(null));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test';
        $this->assertEquals($queueUrl, $queue->getQueue($queueUrl));
    }

    public function testGetQueueProperlyResolvesFifoUrlWithoutPrefix()
    {
        $this->queueName = 'emails.fifo';
        $this->queueUrl = $this->prefix . $this->queueName;
        $queue = new SqsQueue($this->sqs, $this->queueUrl);
        $this->assertEquals($this->queueUrl, $queue->getQueue(null));
        $fifoQueueUrl = $this->baseUrl . '/' . $this->account . '/test.fifo';
        $this->assertEquals($fifoQueueUrl, $queue->getQueue($fifoQueueUrl));
    }

    public function testGetQueueProperlyResolvesUrlWithSuffix()
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix, $suffix = '-staging');
        $this->assertEquals($this->queueUrl . $suffix, $queue->getQueue(null));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test' . $suffix;
        $this->assertEquals($queueUrl, $queue->getQueue('test'));
    }

    public function testGetQueueProperlyResolvesFifoUrlWithSuffix()
    {
        $this->queueName = 'emails.fifo';
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix, $suffix = '-staging');
        $this->assertEquals("{$this->prefix}emails-staging.fifo", $queue->getQueue(null));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test' . $suffix . '.fifo';
        $this->assertEquals($queueUrl, $queue->getQueue('test.fifo'));
    }

    public function testGetQueueEnsuresTheQueueIsOnlySuffixedOnce()
    {
        $queue = new SqsQueue($this->sqs, "{$this->queueName}-staging", $this->prefix, $suffix = '-staging');
        $this->assertEquals($this->queueUrl . $suffix, $queue->getQueue(null));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test' . $suffix;
        $this->assertEquals($queueUrl, $queue->getQueue('test-staging'));
    }

    public function testGetFifoQueueEnsuresTheQueueIsOnlySuffixedOnce()
    {
        $queue = new SqsQueue($this->sqs, "{$this->queueName}-staging.fifo", $this->prefix, $suffix = '-staging');
        $this->assertEquals("{$this->prefix}{$this->queueName}{$suffix}.fifo", $queue->getQueue(null));
        $queueUrl = $this->baseUrl . '/' . $this->account . '/test' . $suffix . '.fifo';
        $this->assertEquals($queueUrl, $queue->getQueue('test-staging.fifo'));
    }

    public function testPushProperlyPushesJobObjectOntoSqs()
    {
        $job = new FakeSqsJob;

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->setContainer($container = m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($job, $this->queueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqs()
    {
        // Job will not be dispatched until the PendingDispatch object is destroyed.
        $pendingDispatch = FakeSqsJob::dispatch();

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->setContainer($container = $this->createSpyContainer());
        $queue->expects($this->once())->method('createPayload')->with($pendingDispatch->getJob(), $this->queueName, '')->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with(null)->willReturn($this->queueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload])->andReturn($this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container, fn () => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    public function testPushProperlyPushesJobObjectOntoSqsFairQueue()
    {
        $job = (new FakeSqsJob)->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->setContainer($container = m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($job, $this->queueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload, 'MessageGroupId' => $this->mockedMessageGroupId])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->queueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqsFairQueue()
    {
        $pendingDispatch = FakeSqsJob::dispatch()->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->queueName, $this->account])->getMock();
        $queue->setContainer($container = $this->createSpyContainer());
        $queue->expects($this->once())->method('createPayload')->with($pendingDispatch->getJob(), $this->queueName, '')->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with(null)->willReturn($this->queueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with(['QueueUrl' => $this->queueUrl, 'MessageBody' => $this->mockedPayload, 'MessageGroupId' => $this->mockedMessageGroupId])->andReturn($this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container, fn () => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    public function testPushProperlyPushesJobStringOntoSqsFifoQueue()
    {
        Str::createUuidsUsing(fn () => $this->createMockedUuid($this->mockedDeduplicationId));

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $queue->setContainer($container = m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->fifoQueueName,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($this->mockedJob, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();

        Str::createUuidsNormally();
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueue()
    {
        Str::createUuidsUsing(fn () => $this->createMockedUuid($this->mockedDeduplicationId));

        $job = (new FakeSqsJob)->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $queue->setContainer($container = m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($job, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();

        Str::createUuidsNormally();
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueueWithMessageGroupMethod()
    {
        Str::createUuidsUsing(fn () => $this->createMockedUuid($this->mockedDeduplicationId));

        $job = $this->getMockBuilder(FakeSqsJobWithMessageGroup::class)->onlyMethods(['messageGroup'])->getMock();
        $job->expects($this->once())->method('messageGroup')->willReturn($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $queue->setContainer($container = m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($job, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();

        Str::createUuidsNormally();
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueueWithMessageGroupPropertyOverridingMethod()
    {
        Str::createUuidsUsing(fn () => $this->createMockedUuid($this->mockedDeduplicationId));

        $job = $this->getMockBuilder(FakeSqsJobWithMessageGroup::class)->onlyMethods(['messageGroup'])->getMock();

        // Ensure the messageGroup method is not called when a messageGroup property is provided.
        $job->expects($this->never())->method('messageGroup')->willReturn('this-should-not-be-used');
        $job->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $queue->setContainer($container = m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($job, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();

        Str::createUuidsNormally();
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueueWithDeduplicationId()
    {
        $job = $this->getMockBuilder(FakeSqsJobWithDeduplication::class)->onlyMethods(['deduplicationId'])->getMock();
        $job->expects($this->once())->method('deduplicationId')->with($this->mockedPayload, $this->fifoQueueName)->willReturn($this->mockedDeduplicationId);
        $job->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $queue->setContainer($container = m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($job, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    public function testPushProperlyPushesJobObjectOntoSqsFifoQueueWithDeduplicator()
    {
        $job = $this->getMockBuilder(FakeSqsJobWithDeduplication::class)->onlyMethods(['deduplicationId'])->getMock();

        // Ensure the deduplicationId method is not called when a deduplicator callback is provided.
        $job->expects($this->never())->method('deduplicationId')->willReturn('this-should-not-be-used');
        $job->onGroup($this->mockedMessageGroupId)->withDeduplicator(function ($payload, $queue) {
            $this->assertEquals($this->mockedPayload, $payload);
            $this->assertEquals($this->fifoQueueName, $queue);

            return $this->mockedDeduplicationId;
        });

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $queue->setContainer($container = m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($job, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->push($job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqsFifoQueue()
    {
        Str::createUuidsUsing(fn () => $this->createMockedUuid($this->mockedDeduplicationId));

        $pendingDispatch = FakeSqsJob::dispatch()->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $queue->setContainer($container = $this->createSpyContainer());
        $queue->expects($this->once())->method('createPayload')->with($pendingDispatch->getJob(), $this->fifoQueueName, '')->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with(null)->willReturn($this->fifoQueueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container, fn () => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->shouldHaveReceived('bound')->with('events')->twice();

        Str::createUuidsNormally();
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqsFifoQueueWithDeduplicationId()
    {
        FakeSqsJobWithDeduplication::createDeduplicationIdsUsing(fn ($payload, $queue) => $this->mockedDeduplicationId);

        $pendingDispatch = FakeSqsJobWithDeduplication::dispatch()->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $queue->setContainer($container = $this->createSpyContainer());
        $queue->expects($this->once())->method('createPayload')->with($pendingDispatch->getJob(), $this->fifoQueueName, '')->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with(null)->willReturn($this->fifoQueueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container, fn () => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->shouldHaveReceived('bound')->with('events')->twice();

        FakeSqsJobWithDeduplication::createDeduplicationIdsNormally();
    }

    public function testPendingDispatchProperlyPushesJobObjectOntoSqsFifoQueueWithDeduplicator()
    {
        FakeSqsJobWithDeduplication::createDeduplicationIdsUsing(function ($payload, $queue) {
            $this->fail('The deduplicationId method should not be called when a deduplicator callback is provided.');

            return 'this-should-not-be-used';
        });

        $pendingDispatch = FakeSqsJobWithDeduplication::dispatch()->onGroup($this->mockedMessageGroupId)->withDeduplicator(function ($payload, $queue) {
            $this->assertEquals($this->mockedPayload, $payload);
            $this->assertEquals($this->fifoQueueName, $queue);

            return $this->mockedDeduplicationId;
        });

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $queue->setContainer($container = $this->createSpyContainer());
        $queue->expects($this->once())->method('createPayload')->with($pendingDispatch->getJob(), $this->fifoQueueName, '')->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with(null)->willReturn($this->fifoQueueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container, fn () => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->shouldHaveReceived('bound')->with('events')->twice();

        FakeSqsJobWithDeduplication::createDeduplicationIdsNormally();
    }

    public function testJobObjectCanBeSerializedOntoSqsFifoQueueWithDeduplicator()
    {
        // Can't reference test case property in serialized closure.
        $deduplicationId = $this->mockedDeduplicationId;

        $pendingDispatch = FakeSqsJobWithDeduplication::dispatch()->onGroup($this->mockedMessageGroupId)->withDeduplicator(function ($payload, $queue) use ($deduplicationId) {
            return $deduplicationId;
        });

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $queue->setContainer($container = $this->createSpyContainer());
        $queue->expects($this->once())->method('getQueue')->with(null)->willReturn($this->fifoQueueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->withArgs(function ($args) {
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

        $dispatcher = new BusDispatcher($container, fn () => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    public function testDelayedPushProperlyPushesJobStringOntoSqsFifoQueueWithoutDelay()
    {
        Str::createUuidsUsing(fn () => $this->createMockedUuid($this->mockedDeduplicationId));

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'secondsUntil', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $queue->setContainer($container = m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($this->mockedJob, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->never())->method('secondsUntil')->with($this->mockedDelay)->willReturn($this->mockedDelay);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->fifoQueueName,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->later($this->mockedDelay, $this->mockedJob, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();

        Str::createUuidsNormally();
    }

    public function testDelayedPushProperlyPushesJobObjectOntoSqsFifoQueueWithoutDelay()
    {
        Str::createUuidsUsing(fn () => $this->createMockedUuid($this->mockedDeduplicationId));

        $job = (new FakeSqsJob)->onGroup($this->mockedMessageGroupId);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'secondsUntil', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $queue->setContainer($container = m::spy(ContainerContract::class));
        $queue->expects($this->once())->method('createPayload')->with($job, $this->fifoQueueName, $this->mockedData)->willReturn($this->mockedPayload);
        $queue->expects($this->never())->method('secondsUntil')->with($this->mockedDelay)->willReturn($this->mockedDelay);
        $queue->expects($this->once())->method('getQueue')->with($this->fifoQueueName)->willReturn($this->fifoQueueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);
        $id = $queue->later($this->mockedDelay, $job, $this->mockedData, $this->fifoQueueName);
        $this->assertEquals($this->mockedMessageId, $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();

        Str::createUuidsNormally();
    }

    public function testDelayedPendingDispatchProperlyPushesJobObjectOntoSqsFifoQueueWithoutDelay()
    {
        Str::createUuidsUsing(fn () => $this->createMockedUuid($this->mockedDeduplicationId));

        $pendingDispatch = FakeSqsJob::dispatch()->onGroup($this->mockedMessageGroupId)->delay($this->mockedDelay);

        $queue = $this->getMockBuilder(SqsQueue::class)->onlyMethods(['createPayload', 'getQueue'])->setConstructorArgs([$this->sqs, $this->fifoQueueName, $this->account])->getMock();
        $queue->setContainer($container = $this->createSpyContainer());
        $queue->expects($this->once())->method('createPayload')->with($pendingDispatch->getJob(), $this->fifoQueueName, '')->willReturn($this->mockedPayload);
        $queue->expects($this->once())->method('getQueue')->with(null)->willReturn($this->fifoQueueUrl);
        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->fifoQueueUrl,
            'MessageBody' => $this->mockedPayload,
            'MessageGroupId' => $this->mockedMessageGroupId,
            'MessageDeduplicationId' => $this->mockedDeduplicationId,
        ])->andReturn($this->mockedSendMessageResponseModel);

        $dispatcher = new BusDispatcher($container, fn () => $queue);
        $container->shouldReceive('make')
            ->with(DispatcherContract::class)
            ->andReturn($dispatcher);
        Container::setInstance($container);

        // Destroy object to trigger dispatch.
        unset($pendingDispatch);

        $container->shouldHaveReceived('bound')->with('events')->twice();

        Str::createUuidsNormally();
    }

    public function testPushRawStoresOverflowPayloadAndSendsItsPointer(): void
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
        $store->shouldReceive('put')->once()->with($path, $payload)->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);

        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => $pointer,
        ])->andReturn($this->mockedSendMessageResponseModel);

        $this->assertSame($this->mockedMessageId, $queue->pushRaw($payload, $this->queueName));
    }

    public function testPushRawDoesNotResolveOverflowStorageWhenDisabledOrBelowThreshold(): void
    {
        $payload = json_encode([
            'uuid' => 'test-uuid',
            'job' => 'App\Jobs\TestJob',
            'data' => 'small',
        ], JSON_THROW_ON_ERROR);

        $container = m::mock(ContainerContract::class);
        $container->shouldNotReceive('make');

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);

        $this->sqs->shouldReceive('sendMessage')->once()->with([
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => $payload,
        ])->andReturn($this->mockedSendMessageResponseModel);

        $this->assertSame($this->mockedMessageId, $queue->pushRaw($payload, $this->queueName));
    }

    public function testPushRawAlwaysStoresOverflowPayloadWhenConfigured(): void
    {
        $payload = json_encode([
            'uuid' => 'always-overflow',
            'job' => 'App\Jobs\TestJob',
            'data' => 'small',
        ], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'always-overflow';

        $store = m::mock(CacheRepository::class);
        $store->shouldReceive('put')->once()->with($path, $payload)->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);

        $this->sqs->shouldReceive('sendMessage')->once()->andReturn($this->mockedSendMessageResponseModel);

        $queue->pushRaw($payload, $this->queueName);
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
        $store->shouldReceive('put')->once()->withArgs(
            function (string $candidate, string $stored) use (&$path, $payload): bool {
                $path = $candidate;

                return $stored === $payload;
            }
        )->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);

        $this->sqs->shouldReceive('sendMessage')->once()->withArgs(
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

        $store = m::mock(CacheRepository::class);
        $store->shouldReceive('put')->once()->andReturnFalse();

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);

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

    public function testPushRawRetainsOverflowPayloadWhenSqsDeliveryIsAmbiguous(): void
    {
        $payload = json_encode(['uuid' => 'ambiguous'], JSON_THROW_ON_ERROR);

        $store = m::mock(CacheRepository::class);
        $store->shouldReceive('put')->once()->andReturnTrue();
        $store->shouldNotReceive('forget');

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);

        $queue = new SqsQueue(
            $this->sqs,
            $this->queueName,
            $this->prefix,
            overflowStorage: ['enabled' => true, 'always' => true, 'store' => 'database'],
        );
        $queue->setContainer($container);

        $this->sqs->shouldReceive('sendMessage')->once()->andThrow(new RuntimeException('transport failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transport failed');

        $queue->pushRaw($payload, $this->queueName);
    }

    public function testClearFlushesTheConfiguredOverflowStore(): void
    {
        $store = m::mock(CacheStore::class);
        $store->shouldReceive('flush')->once()->andReturnTrue();

        $repository = m::mock(CacheRepository::class);
        $repository->shouldReceive('getStore')->once()->andReturn($store);

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($repository);

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);

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

        $this->sqs->shouldReceive('purgeQueue')->once()->with(['QueueUrl' => $this->queueUrl]);

        $this->assertSame(5, $queue->clear($this->queueName));
    }

    public function testClearFailsWhenTheOverflowStoreCannotBeFlushed(): void
    {
        $store = m::mock(CacheStore::class);
        $store->shouldReceive('flush')->once()->andReturnFalse();

        $repository = m::mock(CacheRepository::class);
        $repository->shouldReceive('getStore')->once()->andReturn($store);

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($repository);

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->once()->with('cache')->andReturn($cache);

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

        $this->sqs->shouldReceive('purgeQueue')->once()->with(['QueueUrl' => $this->queueUrl]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to clear the SQS overflow payload store.');

        $queue->clear($this->queueName);
    }

    #[DataProvider('overflowClearDisabledProvider')]
    public function testClearDoesNotResolveOverflowStoreUnlessBothFlagsAreEnabled(bool $enabled, bool $flushOnClear): void
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
                ['enabled' => $enabled, 'flush_on_clear' => $flushOnClear, 'store' => 'database'],
            ])
            ->getMock();
        $queue->setContainer($container);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $queue->expects($this->once())->method('size')->with($this->queueName)->willReturn(5);

        $this->sqs->shouldReceive('purgeQueue')->once()->with(['QueueUrl' => $this->queueUrl]);

        $this->assertSame(5, $queue->clear($this->queueName));
    }

    public static function overflowClearDisabledProvider(): array
    {
        return [
            'overflow disabled' => [false, true],
            'flush disabled' => [true, false],
            'both disabled' => [false, false],
        ];
    }

    public function testBulkSendsAllJobsInOneBatchAndPreservesOrder(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer(new Container);
        $queue->expects($this->once())->method('getQueue')->with($this->queueName)->willReturn($this->queueUrl);
        $queue->expects($this->exactly(3))->method('createPayload')->willReturnOnConsecutiveCalls('p1', 'p2', 'p3');

        $captured = null;
        $this->sqs->shouldReceive('sendMessageBatch')->once()->withArgs(
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

    public function testBulkChunksAtTheSqsCountLimit(): void
    {
        $queue = $this->getMockBuilder(SqsQueue::class)
            ->onlyMethods(['getQueue', 'createPayload'])
            ->setConstructorArgs([$this->sqs, $this->queueName, $this->prefix])
            ->getMock();
        $queue->setContainer(new Container);
        $queue->expects($this->once())->method('getQueue')->willReturn($this->queueUrl);
        $queue->method('createPayload')->willReturnCallback(static fn ($job): string => "payload-{$job}");

        $batchSizes = [];
        $this->sqs->shouldReceive('sendMessageBatch')->twice()->withArgs(
            function (array $arguments) use (&$batchSizes): bool {
                $batchSizes[] = count($arguments['Entries']);

                return true;
            }
        )->andReturn(new Result(['Successful' => [], 'Failed' => []]));

        $queue->bulk(array_map('strval', range(1, 15)), 'data', $this->queueName);

        $this->assertSame([10, 5], $batchSizes);
    }

    public function testBulkChunksAtTheCumulativeMessageBodyLimit(): void
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
        $this->sqs->shouldReceive('sendMessageBatch')->twice()->withArgs(
            function (array $arguments) use (&$batchSizes): bool {
                $batchSizes[] = count($arguments['Entries']);

                return true;
            }
        )->andReturn(new Result(['Successful' => [], 'Failed' => []]));

        $queue->bulk(['a', 'b'], 'data', $this->queueName);

        $this->assertSame([1, 1], $batchSizes);
    }

    public function testBulkUsesTheOriginalPayloadForFifoOptionsBeforeOverflow(): void
    {
        $job = (new FakeSqsJob)->onGroup('0')->withDeduplicator(
            static fn (string $payload): string => 'dedupe-' . $payload
        );
        $payload = json_encode(['uuid' => 'fifo-overflow'], JSON_THROW_ON_ERROR);
        $path = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'fifo-overflow';

        $store = m::mock(CacheRepository::class);
        $store->shouldReceive('put')->once()->with($path, $payload)->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

        $container = new Container;
        $container->instance('cache', $cache);

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
        $queue->expects($this->once())->method('getQueue')->willReturn($this->fifoQueueUrl);
        $queue->expects($this->once())->method('createPayload')->willReturn($payload);

        $captured = null;
        $this->sqs->shouldReceive('sendMessageBatch')->once()->withArgs(
            function (array $arguments) use (&$captured): bool {
                $captured = $arguments;

                return true;
            }
        )->andReturn(new Result(['Successful' => [], 'Failed' => []]));

        $queue->bulk([$job], 'data', $this->fifoQueueName);

        $this->assertSame('0', $captured['Entries'][0]['MessageGroupId']);
        $this->assertSame('dedupe-' . $payload, $captured['Entries'][0]['MessageDeduplicationId']);
        $this->assertSame(
            json_encode(['@pointer' => $path], JSON_THROW_ON_ERROR),
            $captured['Entries'][0]['MessageBody']
        );
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

    public function testBulkRaisesQueuedEventsOnlyForSuccessfulEntries(): void
    {
        $events = m::mock(EventDispatcher::class);
        $events->shouldReceive('hasListeners')->with(JobQueueing::class)->andReturnTrue();
        $events->shouldReceive('hasListeners')->with(JobQueued::class)->andReturnTrue();
        $dispatched = [];
        $events->shouldReceive('dispatch')->andReturnUsing(
            function (object $event) use (&$dispatched): object {
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

        $this->sqs->shouldReceive('sendMessageBatch')->once()->andReturn(new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'successful-id']],
            'Failed' => [['Id' => '1', 'Code' => 'InternalError', 'Message' => 'failed']],
        ]));

        try {
            $queue->bulk(['a', 'b'], 'data', $this->queueName);
            $this->fail('The partial batch failure was not thrown.');
        } catch (SqsException $exception) {
            $this->assertSame('InternalError', $exception->getAwsErrorCode());
        }

        $queueing = array_values(array_filter($dispatched, static fn ($event) => $event instanceof JobQueueing));
        $queued = array_values(array_filter($dispatched, static fn ($event) => $event instanceof JobQueued));

        $this->assertCount(2, $queueing);
        $this->assertCount(1, $queued);
        $this->assertSame('successful-id', $queued[0]->id);
    }

    public function testBulkCleansOnlyRejectedOverflowPointers(): void
    {
        $firstPayload = json_encode(['uuid' => 'accepted'], JSON_THROW_ON_ERROR);
        $secondPayload = json_encode(['uuid' => 'rejected'], JSON_THROW_ON_ERROR);
        $acceptedPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'accepted';
        $rejectedPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'rejected';

        $store = m::mock(CacheRepository::class);
        $store->shouldReceive('put')->once()->with($acceptedPath, $firstPayload)->andReturnTrue();
        $store->shouldReceive('put')->once()->with($rejectedPath, $secondPayload)->andReturnTrue();
        $store->shouldReceive('forget')->once()->with($rejectedPath)->andReturnTrue();
        $store->shouldNotReceive('forget')->with($acceptedPath);

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

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

        $this->sqs->shouldReceive('sendMessageBatch')->once()->andReturn(new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'accepted-id']],
            'Failed' => [['Id' => '1', 'Code' => 'Rejected', 'Message' => 'invalid']],
        ]));

        $this->expectException(SqsException::class);

        $queue->bulk(['a', 'b'], 'data', $this->queueName);
    }

    public function testBulkCleansEarlierWritesWhenAWriteFailsBeforeSending(): void
    {
        $firstPayload = json_encode(['uuid' => 'first'], JSON_THROW_ON_ERROR);
        $secondPayload = json_encode(['uuid' => 'second'], JSON_THROW_ON_ERROR);
        $firstPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'first';
        $secondPath = SqsQueue::EXTENDED_PAYLOAD_CACHE_PREFIX . 'second';

        $store = m::mock(CacheRepository::class);
        $store->shouldReceive('put')->once()->with($firstPath, $firstPayload)->ordered()->andReturnTrue();
        $store->shouldReceive('put')->once()->with($secondPath, $secondPayload)->ordered()->andReturnFalse();
        $store->shouldReceive('forget')->once()->with($firstPath)->andReturnTrue();

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to store the SQS overflow payload');

        $queue->bulk(['a', 'b'], 'data', $this->queueName);
    }

    public function testBulkRetainsAmbiguousChunkPointersAndNeverWritesLaterChunks(): void
    {
        $store = m::mock(CacheRepository::class);
        $store->shouldReceive('put')->times(10)->andReturnTrue();
        $store->shouldNotReceive('forget');

        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->once()->with('database')->andReturn($store);

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
            static fn ($job): string => json_encode(['uuid' => "job-{$job}"], JSON_THROW_ON_ERROR)
        );

        $this->sqs->shouldReceive('sendMessageBatch')->once()->andThrow(new RuntimeException('transport failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transport failed');

        $queue->bulk(array_map('strval', range(1, 11)), 'data', $this->queueName);
    }

    public function testBulkDefersOnePreparedBatchUntilTheTransactionCommits(): void
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
        $this->sqs->shouldReceive('sendMessageBatch')->once()->andReturnUsing(
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

    public function testBulkDoesNothingForEmptyInput(): void
    {
        $queue = new SqsQueue($this->sqs, $this->queueName, $this->prefix);
        $queue->setContainer(new Container);

        $this->sqs->shouldNotReceive('sendMessageBatch');

        $this->assertNull($queue->bulk([], 'data', $this->queueName));
    }
}
