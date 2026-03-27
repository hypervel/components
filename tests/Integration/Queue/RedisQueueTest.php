<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\RedisQueueTest;

use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Queue\RedisQueue;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Redis;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('redis')]
/**
 * @internal
 * @coversNothing
 */
class RedisQueueTest extends TestCase
{
    use InteractsWithRedis;
    use InteractsWithTime;

    private RedisQueue $queue;

    public function testExpiredJobsArePopped()
    {
        $default = $this->app['config']->get('queue.connections.redis.queue');

        $this->setQueue($default);

        $jobs = [
            new RedisQueueIntegrationTestJob(0),
            new RedisQueueIntegrationTestJob(1),
            new RedisQueueIntegrationTestJob(2),
            new RedisQueueIntegrationTestJob(3),
        ];

        $this->queue->later(1000, $jobs[0]);
        $this->queue->later(-200, $jobs[1]);
        $this->queue->later(-300, $jobs[2]);
        $this->queue->later(-100, $jobs[3]);

        $this->assertEquals($jobs[2], unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $this->assertEquals($jobs[1], unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $this->assertEquals($jobs[3], unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $this->assertNull($this->queue->pop());

        $this->assertSame(1, $this->redisConnection()->zcard("queues:{$default}:delayed"));
        $this->assertSame(3, $this->redisConnection()->zcard("queues:{$default}:reserved"));
    }

    public function testPopProperlyPopsJobOffOfRedis()
    {
        $default = $this->app['config']->get('queue.connections.redis.queue');

        $this->setQueue($default);

        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->push($job);

        $before = $this->currentTime();
        /** @var RedisJob $redisJob */
        $redisJob = $this->queue->pop();
        $after = $this->currentTime();

        $this->assertEquals($job, unserialize(json_decode($redisJob->getRawBody())->data->command));
        $this->assertSame(1, $redisJob->attempts());
        $this->assertEquals($job, unserialize(json_decode($redisJob->getReservedJob())->data->command));
        $this->assertSame(1, json_decode($redisJob->getReservedJob())->attempts);
        $this->assertSame($redisJob->getJobId(), json_decode($redisJob->getReservedJob())->id);

        $this->assertSame(1, $this->redisConnection()->zcard("queues:{$default}:reserved"));
        $result = $this->redisConnection()->zrangebyscore("queues:{$default}:reserved", -INF, INF, ['withscores' => true]);
        $reservedJob = array_key_first($result);
        $score = (int) $result[$reservedJob];
        $this->assertLessThanOrEqual($score, $before + 60);
        $this->assertGreaterThanOrEqual($score, $after + 60);
        $this->assertEquals($job, unserialize(json_decode($reservedJob)->data->command));
    }

    public function testPopProperlyPopsDelayedJobOffOfRedis()
    {
        $default = $this->app['config']->get('queue.connections.redis.queue');

        $this->setQueue($default);

        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->later(-10, $job);

        $before = $this->currentTime();
        $this->assertEquals($job, unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $after = $this->currentTime();

        $this->assertSame(1, $this->redisConnection()->zcard("queues:{$default}:reserved"));
        $result = $this->redisConnection()->zrangebyscore("queues:{$default}:reserved", -INF, INF, ['withscores' => true]);
        $reservedJob = array_key_first($result);
        $score = (int) $result[$reservedJob];
        $this->assertLessThanOrEqual($score, $before + 60);
        $this->assertGreaterThanOrEqual($score, $after + 60);
        $this->assertEquals($job, unserialize(json_decode($reservedJob)->data->command));
    }

    public function testPopPopsDelayedJobOffOfRedisWhenExpireNull()
    {
        $default = $this->app['config']->get('queue.connections.redis.queue');

        $this->setQueue($default, retryAfter: null);

        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->later(-10, $job);

        $before = $this->currentTime();
        $this->assertEquals($job, unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $after = $this->currentTime();

        $this->assertSame(1, $this->redisConnection()->zcard("queues:{$default}:reserved"));
        $result = $this->redisConnection()->zrangebyscore("queues:{$default}:reserved", -INF, INF, ['withscores' => true]);
        $reservedJob = array_key_first($result);
        $score = (int) $result[$reservedJob];
        $this->assertLessThanOrEqual($score, $before);
        $this->assertGreaterThanOrEqual($score, $after);
        $this->assertEquals($job, unserialize(json_decode($reservedJob)->data->command));
    }

    public function testBlockingPopProperlyPopsJobOffOfRedis()
    {
        $default = $this->app['config']->get('queue.connections.redis.queue');

        $this->setQueue($default, blockFor: 5);

        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->push($job);

        /** @var RedisJob $redisJob */
        $redisJob = $this->queue->pop();

        $this->assertNotNull($redisJob);
        $this->assertEquals($job, unserialize(json_decode($redisJob->getReservedJob())->data->command));
    }

    public function testBlockingPopProperlyPopsExpiredJobs()
    {
        Str::createUuidsUsing(fn () => '00000000-0000-0000-0000-000000000000');

        $default = $this->app['config']->get('queue.connections.redis.queue');

        $this->setQueue($default, blockFor: 5);

        $jobs = [
            new RedisQueueIntegrationTestJob(0),
            new RedisQueueIntegrationTestJob(1),
        ];

        try {
            $this->queue->later(-200, $jobs[0]);
            $this->queue->later(-200, $jobs[1]);

            $this->assertEquals($jobs[0], unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
            $this->assertEquals($jobs[1], unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));

            $this->assertSame(0, $this->redisConnection()->llen('queues:default:notify'));
            $this->assertSame(0, $this->redisConnection()->zcard("queues:{$default}:delayed"));
            $this->assertSame(2, $this->redisConnection()->zcard("queues:{$default}:reserved"));
        } finally {
            Str::createUuidsNormally();
        }
    }

    public function testNotExpireJobsWhenExpireNull()
    {
        $default = $this->app['config']->get('queue.connections.redis.queue');

        $this->setQueue($default, retryAfter: null);

        $failed = new RedisQueueIntegrationTestJob(-20);
        $this->queue->push($failed);

        $beforeFailPop = $this->currentTime();
        $this->queue->pop();
        $afterFailPop = $this->currentTime();

        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->push($job);

        $before = $this->currentTime();
        $this->assertEquals($job, unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $after = $this->currentTime();

        $this->assertSame(2, $this->redisConnection()->zcard("queues:{$default}:reserved"));
        $result = $this->redisConnection()->zrangebyscore("queues:{$default}:reserved", -INF, INF, ['withscores' => true]);

        foreach ($result as $payload => $score) {
            $command = unserialize(json_decode($payload)->data->command);

            $this->assertInstanceOf(RedisQueueIntegrationTestJob::class, $command);
            $this->assertContains($command->i, [10, -20]);

            $score = (int) $score;

            if ($command->i === 10) {
                $this->assertLessThanOrEqual($score, $before);
                $this->assertGreaterThanOrEqual($score, $after);
            } else {
                $this->assertLessThanOrEqual($score, $beforeFailPop);
                $this->assertGreaterThanOrEqual($score, $afterFailPop);
            }
        }
    }

    public function testExpireJobsWhenExpireSet()
    {
        $default = $this->app['config']->get('queue.connections.redis.queue');

        $this->setQueue($default, retryAfter: 30);

        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->push($job);

        $before = $this->currentTime();
        $this->assertEquals($job, unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $after = $this->currentTime();

        $this->assertSame(1, $this->redisConnection()->zcard("queues:{$default}:reserved"));
        $result = $this->redisConnection()->zrangebyscore("queues:{$default}:reserved", -INF, INF, ['withscores' => true]);
        $reservedJob = array_key_first($result);
        $score = (int) $result[$reservedJob];
        $this->assertLessThanOrEqual($score, $before + 30);
        $this->assertGreaterThanOrEqual($score, $after + 30);
        $this->assertEquals($job, unserialize(json_decode($reservedJob)->data->command));
    }

    public function testRelease()
    {
        $default = $this->app['config']->get('queue.connections.redis.queue');

        $this->setQueue($default);

        $job = new RedisQueueIntegrationTestJob(30);
        $this->queue->push($job);

        /** @var RedisJob $redisJob */
        $redisJob = $this->queue->pop();
        $before = $this->currentTime();
        $redisJob->release(1000);
        $after = $this->currentTime();

        $this->assertSame(1, $this->redisConnection()->zcard("queues:{$default}:delayed"));

        $results = $this->redisConnection()->zrangebyscore("queues:{$default}:delayed", -INF, INF, ['withscores' => true]);
        $payload = array_key_first($results);
        $score = (int) $results[$payload];

        $this->assertGreaterThanOrEqual($before + 1000, $score);
        $this->assertLessThanOrEqual($after + 1000, $score);

        $decoded = json_decode($payload);

        $this->assertSame(1, $decoded->attempts);
        $this->assertEquals($job, unserialize($decoded->data->command));
        $this->assertNull($this->queue->pop());
    }

    public function testReleaseInThePast()
    {
        $default = $this->app['config']->get('queue.connections.redis.queue');

        $this->setQueue($default);

        $job = new RedisQueueIntegrationTestJob(30);
        $this->queue->push($job);

        /** @var RedisJob $redisJob */
        $redisJob = $this->queue->pop();
        $redisJob->release(-3);

        $this->assertInstanceOf(RedisJob::class, $this->queue->pop());
    }

    public function testDelete()
    {
        $default = $this->app['config']->get('queue.connections.redis.queue');

        $this->setQueue($default);

        $job = new RedisQueueIntegrationTestJob(30);
        $this->queue->push($job);

        /** @var RedisJob $redisJob */
        $redisJob = $this->queue->pop();
        $redisJob->delete();

        $this->assertSame(0, $this->redisConnection()->zcard("queues:{$default}:delayed"));
        $this->assertSame(0, $this->redisConnection()->zcard("queues:{$default}:reserved"));
        $this->assertSame(0, $this->redisConnection()->llen("queues:{$default}"));
        $this->assertNull($this->queue->pop());
    }

    public function testClear()
    {
        $default = $this->app['config']->get('queue.connections.redis.queue');

        $this->setQueue($default);

        $job1 = new RedisQueueIntegrationTestJob(30);
        $job2 = new RedisQueueIntegrationTestJob(40);

        $this->queue->push($job1);
        $this->queue->push($job2);

        $this->assertSame(2, $this->queue->clear(null));
        $this->assertSame(0, $this->queue->size());
        $this->assertSame(0, $this->redisConnection()->llen('queues:default:notify'));
    }

    public function testSize()
    {
        $this->setQueue($this->app['config']->get('queue.connections.redis.queue'));

        $this->assertSame(0, $this->queue->size());
        $this->queue->push(new RedisQueueIntegrationTestJob(1));
        $this->assertSame(1, $this->queue->size());
        $this->queue->later(60, new RedisQueueIntegrationTestJob(2));
        $this->assertSame(2, $this->queue->size());
        $this->queue->push(new RedisQueueIntegrationTestJob(3));
        $this->assertSame(3, $this->queue->size());

        $job = $this->queue->pop();

        $this->assertSame(3, $this->queue->size());
        $job->delete();
        $this->assertSame(2, $this->queue->size());
    }

    public function testPushJobQueueingAndJobQueuedEvents()
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->withArgs(function (JobQueueing $jobQueueing) {
            $this->assertInstanceOf(RedisQueueIntegrationTestJob::class, $jobQueueing->job);

            return true;
        })->andReturnNull()->once();
        $events->shouldReceive('dispatch')->withArgs(function (JobQueued $jobQueued) {
            $this->assertInstanceOf(RedisQueueIntegrationTestJob::class, $jobQueued->job);
            $this->assertIsString($jobQueued->id);

            return true;
        })->andReturnNull()->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(Dispatcher::class)->andReturn(true)->twice();
        $container->shouldReceive('make')->with(Dispatcher::class)->andReturn($events)->twice();

        $queue = new RedisQueue($this->app->make(RedisFactory::class), $this->app['config']->get('queue.connections.redis.queue'));
        $queue->setContainer($container);
        $queue->setConnectionName('redis');

        $queue->push(new RedisQueueIntegrationTestJob(5));
    }

    public function testBulkJobQueuedEvent()
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->with(m::type(JobQueueing::class))->andReturnNull()->times(3);
        $events->shouldReceive('dispatch')->with(m::type(JobQueued::class))->andReturnNull()->times(3);

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(Dispatcher::class)->andReturn(true)->times(6);
        $container->shouldReceive('make')->with(Dispatcher::class)->andReturn($events)->times(6);

        $queue = new RedisQueue($this->app->make(RedisFactory::class), $this->app['config']->get('queue.connections.redis.queue'));
        $queue->setContainer($container);
        $queue->setConnectionName('redis');

        $queue->bulk([
            new RedisQueueIntegrationTestJob(5),
            new RedisQueueIntegrationTestJob(10),
            new RedisQueueIntegrationTestJob(15),
        ]);
    }

    public function testDelayedJobsWorkWithPhpRedisSerializationEnabled()
    {
        $connection = Redis::connection('default');
        $client = $connection->client();

        $originalSerializer = $client->getOption(\Redis::OPT_SERIALIZER);
        $client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);

        try {
            $this->setQueue($this->app['config']->get('queue.connections.redis.queue'));

            $job = new RedisQueueIntegrationTestJob(42);
            $this->queue->later(-10, $job);

            $poppedJob = $this->queue->pop();

            $this->assertNotNull($poppedJob, 'Delayed job should be retrievable after delay expires');

            $rawBody = $poppedJob->getRawBody();
            $decoded = json_decode($rawBody);

            $this->assertNotNull($decoded, 'Job payload should be valid JSON');
            $this->assertObjectHasProperty('data', $decoded, 'Decoded payload should have data property');

            $command = unserialize($decoded->data->command);
            $this->assertEquals($job, $command, 'Unserialized job should match original');
            $this->assertSame(42, $command->i, 'Job property should be preserved');
        } finally {
            $client->setOption(\Redis::OPT_SERIALIZER, $originalSerializer);
        }
    }

    private function setQueue(?string $default = null, ?string $connection = null, ?int $retryAfter = 60, ?int $blockFor = null): void
    {
        $this->queue = new RedisQueue(
            $this->app->make(RedisFactory::class),
            $default ?? $this->app['config']->get('queue.connections.redis.queue'),
            $connection,
            $retryAfter,
            $blockFor,
        );
        $this->queue->setContainer($this->app);
        $this->queue->setConnectionName('redis');
    }

    private function redisConnection(): RedisProxy
    {
        return Redis::connection('default');
    }
}

class RedisQueueIntegrationTestJob
{
    public function __construct(
        public int $i,
    ) {
    }

    public function handle(): void
    {
    }
}
