<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Redis\RedisQueueTest;

use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Events\JobPayloadFinalizing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\Jobs\InspectedJob;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Queue\RedisQueue;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Redis;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Redis as PhpRedis;
use RedisCluster;
use ReflectionMethod;

#[RequiresPhpExtension('redis')]
class RedisQueueTest extends TestCase
{
    use InteractsWithRedis;
    use InteractsWithTime;

    private RedisQueue $queue;

    public function testExpiredJobsArePopped(): void
    {
        $default = $this->defaultQueueName();

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

        $redisKey = $this->getQueueRedisKey($default);
        $this->assertSame(1, $this->redisConnection()->zcard("{$redisKey}:delayed"));
        $this->assertSame(3, $this->redisConnection()->zcard("{$redisKey}:reserved"));
    }

    public function testFractionalDelayedAndReservedJobsDoNotMigrateEarly(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $default = $this->defaultQueueName();
        $this->setQueue($default, retryAfter: 1);
        $job = new RedisQueueIntegrationTestJob(10);

        $this->queue->later(1, $job);

        $redisKey = $this->getQueueRedisKey($default);
        $delayed = $this->redisConnection()->zrangebyscore("{$redisKey}:delayed", -INF, INF, ['withscores' => true]);
        $this->assertSame(1002.0, (float) reset($delayed));

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1001.900000'));

        $this->assertNull($this->queue->pop());

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1002.000000'));

        $reservedJob = $this->queue->pop();
        $this->assertInstanceOf(RedisJob::class, $reservedJob);

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1002.900000'));

        $this->assertNull($this->queue->pop());

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1003.000000'));

        $this->assertInstanceOf(RedisJob::class, $this->queue->pop());
    }

    public function testPopProperlyPopsJobOffOfRedis(): void
    {
        $default = $this->defaultQueueName();

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

        $redisKey = $this->getQueueRedisKey($default);
        $this->assertSame(1, $this->redisConnection()->zcard("{$redisKey}:reserved"));
        $result = $this->redisConnection()->zrangebyscore("{$redisKey}:reserved", -INF, INF, ['withscores' => true]);
        $reservedJob = array_key_first($result);
        $score = (int) $result[$reservedJob];
        $this->assertLessThanOrEqual($score, $before + 60);
        $this->assertGreaterThanOrEqual($score, $after + 61);
        $this->assertEquals($job, unserialize(json_decode($reservedJob)->data->command));
    }

    #[DataProvider('invalidRawPayloads')]
    public function testInvalidRawPayloadIsReservedWithoutMutation(
        string $payload,
        ?string $expectedId,
        string $expectedMessage,
    ): void {
        $default = $this->defaultQueueName();
        $this->setQueue($default);

        $this->queue->pushRaw($payload);

        $job = $this->queue->pop();

        $this->assertInstanceOf(RedisJob::class, $job);
        $this->assertSame($payload, $job->getRawBody());
        $this->assertSame($payload, $job->getReservedJob());
        $this->assertSame($expectedId, $job->getJobId());
        $this->assertSame(1, $job->attempts());
        $redisKey = $this->getQueueRedisKey($default);
        $this->assertSame(
            [$payload],
            $this->redisConnection()->zrange("{$redisKey}:reserved", 0, -1),
        );
        $this->assertSame(0, $this->redisConnection()->llen("{$redisKey}:notify"));

        try {
            $job->payload();
            $this->fail('Expected the payload to be rejected.');
        } catch (InvalidPayloadException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
            $this->assertSame($payload, $e->value);
        }

        $job->delete();

        $this->assertSame(0, $this->redisConnection()->zcard("{$redisKey}:reserved"));
    }

    public static function invalidRawPayloads(): array
    {
        return [
            'malformed JSON' => ['{invalid', null, 'Unable to decode the queue job payload'],
            'scalar JSON' => ['true', null, 'does not contain a valid job and data'],
            'array JSON' => ['[]', null, 'does not contain a valid job and data'],
            'raw zero' => ['0', null, 'does not contain a valid job and data'],
            'missing attempts' => ['{"id":"job-id","job":"foo","data":[]}', 'job-id', 'does not contain a valid attempts count'],
            'nonnumeric attempts' => ['{"id":"job-id","job":"foo","data":[],"attempts":"invalid"}', 'job-id', 'does not contain a valid attempts count'],
        ];
    }

    public function testNumericStringAttemptsAreIncrementedAtomically(): void
    {
        $default = $this->defaultQueueName();
        $this->setQueue($default);

        $this->queue->pushRaw('{"id":"job-id","job":"foo","data":[],"attempts":"2"}');

        $job = $this->queue->pop();

        $this->assertInstanceOf(RedisJob::class, $job);
        $this->assertSame(3, $job->attempts());
        $this->assertSame(3, json_decode($job->getReservedJob(), true, flags: JSON_THROW_ON_ERROR)['attempts']);
        $this->assertSame('job-id', $job->getJobId());
        $this->assertSame('foo', $job->payload()['job']);
    }

    public function testFractionalAttemptsReachPhpAsAnInteger(): void
    {
        $default = $this->defaultQueueName();
        $this->setQueue($default);

        $this->queue->pushRaw('{"id":"job-id","job":"foo","data":[],"attempts":1.5}');

        $job = $this->queue->pop();

        $this->assertInstanceOf(RedisJob::class, $job);
        $this->assertSame(2, $job->attempts());
        $this->assertSame(2.5, json_decode($job->getReservedJob(), true, flags: JSON_THROW_ON_ERROR)['attempts']);
    }

    public function testPopProperlyPopsDelayedJobOffOfRedis(): void
    {
        $default = $this->defaultQueueName();

        $this->setQueue($default);

        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->later(-10, $job);

        $before = $this->currentTime();
        $this->assertEquals($job, unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $after = $this->currentTime();

        $redisKey = $this->getQueueRedisKey($default);
        $this->assertSame(1, $this->redisConnection()->zcard("{$redisKey}:reserved"));
        $result = $this->redisConnection()->zrangebyscore("{$redisKey}:reserved", -INF, INF, ['withscores' => true]);
        $reservedJob = array_key_first($result);
        $score = (int) $result[$reservedJob];
        $this->assertLessThanOrEqual($score, $before + 60);
        $this->assertGreaterThanOrEqual($score, $after + 61);
        $this->assertEquals($job, unserialize(json_decode($reservedJob)->data->command));
    }

    public function testPopPopsDelayedJobOffOfRedisWhenExpireNull(): void
    {
        $default = $this->defaultQueueName();

        $this->setQueue($default, retryAfter: null);

        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->later(-10, $job);

        $before = $this->currentTime();
        $this->assertEquals($job, unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $after = $this->currentTime();

        $redisKey = $this->getQueueRedisKey($default);
        $this->assertSame(1, $this->redisConnection()->zcard("{$redisKey}:reserved"));
        $result = $this->redisConnection()->zrangebyscore("{$redisKey}:reserved", -INF, INF, ['withscores' => true]);
        $reservedJob = array_key_first($result);
        $score = (int) $result[$reservedJob];
        $this->assertLessThanOrEqual($score, $before);
        $this->assertGreaterThanOrEqual($score, $after);
        $this->assertEquals($job, unserialize(json_decode($reservedJob)->data->command));
    }

    public function testBlockingPopProperlyPopsJobOffOfRedis(): void
    {
        $default = $this->defaultQueueName();

        $this->setQueue($default, blockFor: 5);

        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->push($job);

        /** @var RedisJob $redisJob */
        $redisJob = $this->queue->pop();

        $this->assertNotNull($redisJob);
        $this->assertEquals($job, unserialize(json_decode($redisJob->getReservedJob())->data->command));
    }

    public function testBlockingPopProperlyPopsExpiredJobs(): void
    {
        Str::createUuidsUsing(fn () => '00000000-0000-0000-0000-000000000000');

        $default = $this->defaultQueueName();

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

            $redisKey = $this->getQueueRedisKey($default);
            $this->assertSame(0, $this->redisConnection()->llen("{$redisKey}:notify"));
            $this->assertSame(0, $this->redisConnection()->zcard("{$redisKey}:delayed"));
            $this->assertSame(2, $this->redisConnection()->zcard("{$redisKey}:reserved"));
        } finally {
            Str::createUuidsNormally();
        }
    }

    public function testNotExpireJobsWhenExpireNull(): void
    {
        $default = $this->defaultQueueName();

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

        $redisKey = $this->getQueueRedisKey($default);
        $this->assertSame(2, $this->redisConnection()->zcard("{$redisKey}:reserved"));
        $result = $this->redisConnection()->zrangebyscore("{$redisKey}:reserved", -INF, INF, ['withscores' => true]);

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

    public function testExpireJobsWhenExpireSet(): void
    {
        $default = $this->defaultQueueName();

        $this->setQueue($default, retryAfter: 30);

        $job = new RedisQueueIntegrationTestJob(10);
        $this->queue->push($job);

        $before = $this->currentTime();
        $this->assertEquals($job, unserialize(json_decode($this->queue->pop()->getRawBody())->data->command));
        $after = $this->currentTime();

        $redisKey = $this->getQueueRedisKey($default);
        $this->assertSame(1, $this->redisConnection()->zcard("{$redisKey}:reserved"));
        $result = $this->redisConnection()->zrangebyscore("{$redisKey}:reserved", -INF, INF, ['withscores' => true]);
        $reservedJob = array_key_first($result);
        $score = (int) $result[$reservedJob];
        $this->assertLessThanOrEqual($score, $before + 30);
        $this->assertGreaterThanOrEqual($score, $after + 31);
        $this->assertEquals($job, unserialize(json_decode($reservedJob)->data->command));
    }

    public function testRelease(): void
    {
        $default = $this->defaultQueueName();

        $this->setQueue($default);

        $job = new RedisQueueIntegrationTestJob(30);
        $this->queue->push($job);

        /** @var RedisJob $redisJob */
        $redisJob = $this->queue->pop();
        $before = $this->currentTime();
        $redisJob->release(1000);
        $after = $this->currentTime();

        $redisKey = $this->getQueueRedisKey($default);
        $this->assertSame(1, $this->redisConnection()->zcard("{$redisKey}:delayed"));

        $results = $this->redisConnection()->zrangebyscore("{$redisKey}:delayed", -INF, INF, ['withscores' => true]);
        $payload = array_key_first($results);
        $score = (int) $results[$payload];

        $this->assertGreaterThanOrEqual($before + 1000, $score);
        $this->assertLessThanOrEqual($after + 1001, $score);

        $decoded = json_decode($payload);

        $this->assertSame(1, $decoded->attempts);
        $this->assertEquals($job, unserialize($decoded->data->command));
        $this->assertNull($this->queue->pop());
    }

    public function testReleaseInThePast(): void
    {
        $default = $this->defaultQueueName();

        $this->setQueue($default);

        $job = new RedisQueueIntegrationTestJob(30);
        $this->queue->push($job);

        /** @var RedisJob $redisJob */
        $redisJob = $this->queue->pop();
        $redisJob->release(-3);

        $this->assertInstanceOf(RedisJob::class, $this->queue->pop());
    }

    public function testDelete(): void
    {
        $default = $this->defaultQueueName();

        $this->setQueue($default);

        $job = new RedisQueueIntegrationTestJob(30);
        $this->queue->push($job);

        /** @var RedisJob $redisJob */
        $redisJob = $this->queue->pop();
        $redisJob->delete();

        $redisKey = $this->getQueueRedisKey($default);
        $this->assertSame(0, $this->redisConnection()->zcard("{$redisKey}:delayed"));
        $this->assertSame(0, $this->redisConnection()->zcard("{$redisKey}:reserved"));
        $this->assertSame(0, $this->redisConnection()->llen($redisKey));
        $this->assertNull($this->queue->pop());
    }

    public function testClear(): void
    {
        $default = $this->defaultQueueName();

        $this->setQueue($default);

        $job1 = new RedisQueueIntegrationTestJob(30);
        $job2 = new RedisQueueIntegrationTestJob(40);

        $this->queue->push($job1);
        $this->queue->push($job2);

        $this->assertSame(2, $this->queue->clear(null));
        $this->assertSame(0, $this->queue->size());
        $redisKey = $this->getQueueRedisKey($default);
        $this->assertSame(0, $this->redisConnection()->llen("{$redisKey}:notify"));
    }

    public function testSize(): void
    {
        $this->setQueue($this->defaultQueueName());

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

    public function testPushJobQueueingAndJobQueuedEvents(): void
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->with(JobPayloadFinalizing::class)->andReturnFalse()->once();
        $events->shouldReceive('hasListeners')->with(JobQueueing::class)->andReturn(true)->once();
        $events->shouldReceive('hasListeners')->with(JobQueued::class)->andReturn(true)->once();
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
        $container->shouldReceive('bound')->with('events')->andReturn(true)->times(3);
        $container->shouldReceive('make')->with('events')->andReturn($events)->times(3);

        $queue = new RedisQueue($this->app->make(RedisFactory::class), $this->defaultQueueName());
        $queue->setContainer($container);
        $queue->setConnectionName('redis');

        $queue->push(new RedisQueueIntegrationTestJob(5));
    }

    public function testBulkJobQueuedEvent(): void
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->with(JobPayloadFinalizing::class)->andReturnFalse()->times(3);
        $events->shouldReceive('hasListeners')->with(JobQueueing::class)->andReturn(true)->times(3);
        $events->shouldReceive('hasListeners')->with(JobQueued::class)->andReturn(true)->times(3);
        $events->shouldReceive('dispatch')->with(m::type(JobQueueing::class))->andReturnNull()->times(3);
        $events->shouldReceive('dispatch')->with(m::type(JobQueued::class))->andReturnNull()->times(3);

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with('db.transactions')->andReturnFalse()->once();
        $container->shouldReceive('bound')->with('events')->andReturn(true)->times(9);
        $container->shouldReceive('make')->with('events')->andReturn($events)->times(9);

        $queue = new RedisQueue($this->app->make(RedisFactory::class), $this->defaultQueueName());
        $queue->setContainer($container);
        $queue->setConnectionName('redis');

        $queue->bulk([
            new RedisQueueIntegrationTestJob(5),
            new RedisQueueIntegrationTestJob(10),
            new RedisQueueIntegrationTestJob(15),
        ]);
    }

    public function testBulkStoresImmediateAndDelayedJobsWithExactNotifications(): void
    {
        $default = $this->defaultQueueName();
        $this->setQueue($default);

        $this->queue->bulk([
            new RedisQueueIntegrationTestJob(1),
            new RedisQueueIntegrationDelayedJob(2, 60),
            new RedisQueueIntegrationTestJob(3),
        ]);

        $redisKey = $this->getQueueRedisKey($default);
        $immediate = $this->redisConnection()->lrange($redisKey, 0, -1);
        $delayed = $this->redisConnection()->zrange("{$redisKey}:delayed", 0, -1);

        $this->assertSame([1, 3], array_map(
            static fn (string $payload): int => unserialize(json_decode($payload)->data->command)->i,
            $immediate,
        ));
        $this->assertSame([2], array_map(
            static fn (string $payload): int => unserialize(json_decode($payload)->data->command)->i,
            $delayed,
        ));
        $this->assertSame(2, $this->redisConnection()->llen("{$redisKey}:notify"));
    }

    public function testBulkScriptFailureLeavesEarlierWritesAndStopsLaterWrites(): void
    {
        $default = $this->defaultQueueName();
        $this->setQueue($default);
        $redisKey = $this->getQueueRedisKey($default);
        $this->redisConnection()->set($redisKey, 'not-a-list');
        $caught = null;

        try {
            $this->queue->bulk([
                new RedisQueueIntegrationDelayedJob(1, 60),
                new RedisQueueIntegrationTestJob(2),
                new RedisQueueIntegrationDelayedJob(3, 120),
            ]);
        } catch (LuaScriptException $caught) {
        }

        $this->assertInstanceOf(LuaScriptException::class, $caught);
        $delayed = $this->redisConnection()->zrange("{$redisKey}:delayed", 0, -1);
        $this->assertSame([1], array_map(
            static fn (string $payload): int => unserialize(json_decode($payload)->data->command)->i,
            $delayed,
        ));
        $this->assertSame('not-a-list', $this->redisConnection()->get($redisKey));
        $this->assertSame(0, $this->redisConnection()->llen("{$redisKey}:notify"));
    }

    public function testDelayedJobsWorkWithPhpRedisSerializationEnabled(): void
    {
        $connection = Redis::connection('default');

        $connection->withPinnedConnection(function () use ($connection): void {
            $client = $connection->withConnection(
                fn (RedisConnection $connection): \Redis|RedisCluster => $connection->client()
            );

            $originalSerializer = $client->getOption(\Redis::OPT_SERIALIZER);
            $client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);

            try {
                $this->setQueue($this->defaultQueueName());

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
        });
    }

    public function testPendingJobs(): void
    {
        $default = $this->defaultQueueName();
        $this->setQueue($default);
        $this->queue->push(new RedisQueueIntegrationTestJob(99));

        $job = $this->queue->pendingJobs()->sole();

        $this->assertInspectedJob($job, $default, 0);
    }

    public function testDelayedJobs(): void
    {
        $default = $this->defaultQueueName();
        $this->setQueue($default);
        $this->queue->later(60, new RedisQueueIntegrationTestJob(99));

        $job = $this->queue->delayedJobs()->sole();

        $this->assertInspectedJob($job, $default, 0);
    }

    public function testReservedJobs(): void
    {
        $default = $this->defaultQueueName();
        $this->setQueue($default);
        $this->queue->push(new RedisQueueIntegrationTestJob(99));
        $this->queue->pop();

        $job = $this->queue->reservedJobs()->sole();

        $this->assertInspectedJob($job, $default, 1);
    }

    public function testAllPendingJobs(): void
    {
        $default = $this->defaultQueueName();
        $this->setQueue($default);
        $this->queue->push(new RedisQueueIntegrationTestJob(1));
        $this->queue->pushOn('emails', new RedisQueueIntegrationTestJob(2));

        $jobs = $this->queue->allPendingJobs();

        $this->assertCount(2, $jobs);
        $this->assertSame([$default, 'emails'], $jobs->pluck('queue')->sort()->values()->all());
        $jobs->each(fn (InspectedJob $job) => $this->assertInspectedJob($job, $job->queue, 0));
    }

    public function testAllPendingJobsReportExplicitHashTaggedNamesByTopology(): void
    {
        $this->setQueue('{orders}');
        $this->queue->push(new RedisQueueIntegrationTestJob(1));

        $this->assertInspectedJob($this->queue->pendingJobs()->sole(), '{orders}', 0);
        $this->assertInspectedJob(
            $this->queue->allPendingJobs()->sole(),
            $this->usingRedisCluster() ? 'orders' : '{orders}',
            0,
        );
        $this->assertSame(1, $this->queue->totalSize());
        $this->assertSame(1, $this->queue->totalPendingSize());
    }

    public function testAllDelayedJobs(): void
    {
        $default = $this->defaultQueueName();
        $this->setQueue($default);
        $this->queue->later(60, new RedisQueueIntegrationTestJob(1));
        $this->queue->laterOn('emails', 60, new RedisQueueIntegrationTestJob(2));

        $jobs = $this->queue->allDelayedJobs();

        $this->assertCount(2, $jobs);
        $this->assertSame([$default, 'emails'], $jobs->pluck('queue')->sort()->values()->all());
        $jobs->each(fn (InspectedJob $job) => $this->assertInspectedJob($job, $job->queue, 0));
    }

    public function testAllReservedJobs(): void
    {
        $default = $this->defaultQueueName();
        $this->setQueue($default);
        $this->queue->push(new RedisQueueIntegrationTestJob(1));
        $this->queue->pushOn('emails', new RedisQueueIntegrationTestJob(2));
        $this->queue->pop();
        $this->queue->pop('emails');

        $jobs = $this->queue->allReservedJobs();

        $this->assertCount(2, $jobs);
        $this->assertSame([$default, 'emails'], $jobs->pluck('queue')->sort()->values()->all());
        $jobs->each(fn (InspectedJob $job) => $this->assertInspectedJob($job, $job->queue, 1));
    }

    public function testTotalSize(): void
    {
        $this->setQueue($this->defaultQueueName());

        $this->queue->push(new RedisQueueIntegrationTestJob(1));
        $this->queue->pushOn('emails', new RedisQueueIntegrationTestJob(2));
        $this->queue->later(60, new RedisQueueIntegrationTestJob(3));

        $this->assertSame(3, $this->queue->totalSize());
    }

    public function testTotalPendingSize(): void
    {
        $this->setQueue($this->defaultQueueName());

        $this->queue->push(new RedisQueueIntegrationTestJob(1));
        $this->queue->pushOn('emails', new RedisQueueIntegrationTestJob(2));

        $this->assertSame(2, $this->queue->totalPendingSize());
    }

    public function testTotalDelayedSize(): void
    {
        $this->setQueue($this->defaultQueueName());

        $this->queue->later(60, new RedisQueueIntegrationTestJob(1));
        $this->queue->laterOn('emails', 60, new RedisQueueIntegrationTestJob(2));

        $this->assertSame(2, $this->queue->totalDelayedSize());
    }

    public function testTotalReservedSize(): void
    {
        $this->setQueue($this->defaultQueueName());

        $this->queue->push(new RedisQueueIntegrationTestJob(1));
        $this->queue->pushOn('emails', new RedisQueueIntegrationTestJob(2));
        $this->queue->pop();
        $this->queue->pop('emails');

        $this->assertSame(2, $this->queue->totalReservedSize());
    }

    public function testBulkPushesAllJobsOntoQueue(): void
    {
        $this->setQueue('default');

        $this->queue->bulk([
            new RedisQueueIntegrationTestJob(1),
            new RedisQueueIntegrationTestJob(2),
            new RedisQueueIntegrationTestJob(3),
        ], '', 'bulk-test');

        $this->assertSame(3, $this->queue->size('bulk-test'));

        $seen = [];

        for ($i = 0; $i < 3; ++$i) {
            $seen[] = unserialize(json_decode($this->queue->pop('bulk-test')->getRawBody())->data->command)->i;
        }

        sort($seen);

        $this->assertSame([1, 2, 3], $seen);
        $this->assertNull($this->queue->pop('bulk-test'));
    }

    public function testBulkPushesDelayedJobsOntoDelayedQueue(): void
    {
        $this->setQueue('default');

        $this->queue->bulk([
            new RedisQueueIntegrationTestJob(1),
            new RedisQueueIntegrationTestDelayedJob(2),
        ], '', 'bulk-delay');

        $redisKey = $this->getQueueRedisKey('bulk-delay');

        $this->assertSame(1, $this->redisConnection()->llen($redisKey));
        $this->assertSame(1, $this->redisConnection()->zcard("{$redisKey}:delayed"));
    }

    public function testBulkPushesManyJobsOntoQueue(): void
    {
        $this->setQueue('default');

        $jobs = [];

        for ($i = 0; $i < 1050; ++$i) {
            $jobs[] = new RedisQueueIntegrationTestJob($i);
        }

        $this->queue->bulk($jobs, '', 'bulk-many');

        $redisKey = $this->getQueueRedisKey('bulk-many');

        $this->assertSame(1050, $this->queue->size('bulk-many'));
        $this->assertSame(1050, $this->redisConnection()->llen("{$redisKey}:notify"));
    }

    public function testAllQueueNamesReturnsQueuesAcrossMultipleQueues(): void
    {
        $default = $this->defaultQueueName();
        $this->setQueue($default);

        $this->queue->push(new RedisQueueIntegrationTestJob(1));
        $this->queue->pushOn('emails', new RedisQueueIntegrationTestJob(2));
        $this->queue->pushOn('notifications', new RedisQueueIntegrationTestJob(3));

        $names = (new ReflectionMethod($this->queue, 'allQueueNames'))
            ->invoke($this->queue)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$default, 'emails', 'notifications'], $names);
    }

    #[DataProvider('scanRetryOptions')]
    public function testScanningQueueNamesDoesNotDoublePrefixTheMatchPattern(bool $retryScan): void
    {
        $connectionName = $this->createRedisConnectionWithOptions('queue-scan-prefix', [
            'prefix' => 'test_',
            'scan' => PhpRedis::SCAN_PREFIX,
        ]);
        $this->setQueue('default', $connectionName);
        $redis = Redis::connection($connectionName);

        $redis->withPinnedConnection(function () use ($redis, $retryScan): void {
            if ($retryScan) {
                $redis->withConnection(function (RedisConnection $connection): void {
                    $connection->setOption(PhpRedis::OPT_SCAN, PhpRedis::SCAN_RETRY);
                });
            }

            $this->queue->push(new RedisQueueIntegrationTestJob(1));

            $this->assertSame(
                ['default'],
                (new ReflectionMethod($this->queue, 'allQueueNames'))->invoke($this->queue)->all(),
            );
        });
    }

    /**
     * Provide the retry settings used alongside scan prefixing.
     */
    public static function scanRetryOptions(): array
    {
        return [
            'prefix only' => [false],
            'prefix and retry' => [true],
        ];
    }

    public function testTotalSizesPreserveQueueNamesAcrossEveryState(): void
    {
        $this->setQueue();

        foreach (['reports:high', '0'] as $name) {
            $this->queue->pushOn($name, new RedisQueueIntegrationTestJob(1));
            $this->queue->pop($name);
            $this->queue->pushOn($name, new RedisQueueIntegrationTestJob(2));
            $this->queue->laterOn($name, 60, new RedisQueueIntegrationTestJob(3));
        }

        $this->assertSame(6, $this->queue->totalSize());
        $this->assertSame(2, $this->queue->totalPendingSize());
        $this->assertSame(2, $this->queue->totalDelayedSize());
        $this->assertSame(2, $this->queue->totalReservedSize());
    }

    public function testInvalidInspectedPayloadRetainsItsRedisRemovalMember(): void
    {
        $this->setQueue('poison');
        $this->queue->pushRaw('not-json', 'poison');

        try {
            $this->queue->pendingJobs('poison');
            $this->fail('Expected the invalid payload to be rejected.');
        } catch (InvalidPayloadException $exception) {
            $this->assertStringContainsString('on queue [poison]', $exception->getMessage());
            $this->assertSame('not-json', $exception->value);
            $this->assertSame(
                1,
                $this->redisConnection()->lrem($this->getQueueRedisKey('poison'), 1, 'not-json'),
            );
        }

        $this->assertSame(0, $this->redisConnection()->llen($this->getQueueRedisKey('poison')));
    }

    private function assertInspectedJob(InspectedJob $job, ?string $queue, int $attempts): void
    {
        $this->assertSame(RedisQueueIntegrationTestJob::class, $job->name);
        $this->assertSame($queue, $job->queue);
        $this->assertSame($attempts, $job->attempts);
        $this->assertNotNull($job->uuid);
        $this->assertInstanceOf(CarbonImmutable::class, $job->createdAt);
    }

    private function defaultQueueName(): string
    {
        return $this->app->make('config')->string('queue.connections.redis.queue');
    }

    private function setQueue(?string $default = null, ?string $connection = null, ?int $retryAfter = 60, ?int $blockFor = null): void
    {
        $this->queue = new RedisQueue(
            $this->app->make(RedisFactory::class),
            $default ?? $this->defaultQueueName(),
            $connection,
            $retryAfter,
            $blockFor,
        );
        $this->queue->setContainer($this->app);
        $this->queue->setConnectionName('redis');
    }

    private function getQueueRedisKey(?string $queue = null): string
    {
        return (new ReflectionMethod($this->queue, 'getQueueRedisKey'))->invoke($this->queue, $queue);
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

#[Delay(60)]
class RedisQueueIntegrationTestDelayedJob
{
    /**
     * Create a delayed test job.
     */
    public function __construct(
        public int $i,
    ) {
    }

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }
}

class RedisQueueIntegrationDelayedJob extends RedisQueueIntegrationTestJob
{
    public function __construct(int $i, public int $delay)
    {
        parent::__construct($i);
    }
}
