<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Events\Dispatcher;
use Hypervel\Horizon\Events\JobsMigrated;
use Hypervel\Horizon\RedisQueue;
use Hypervel\Queue\LuaScripts;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\Horizon\UnitTestCase;
use Hypervel\Tests\Queue\Fixtures\IntegerQueueName;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use RedisException;

use function Hypervel\Coroutine\parallel;

class RedisQueueTest extends UnitTestCase
{
    public function testReadyNowReadsTheClusterSafeQueueKey(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturnTrue();
        $connection->shouldReceive('lLen')->once()->with('queues:{critical}')->andReturn(3);
        $connection->shouldReceive('lLen')->once()->with('queues:{0}')->andReturn(4);

        $redis = m::mock(Factory::class);
        $redis->shouldReceive('connection')->times(3)->with('default')->andReturn($connection);

        $queue = new RedisQueue($redis, 'default', 'default');

        $this->assertSame(3, $queue->readyNow('critical'));
        $this->assertSame(4, $queue->readyNow(IntegerQueueName::Zero));
    }

    #[TestWith(['critical'])]
    #[TestWith(['{critical}'])]
    public function testMigrationEventsReportTheQueueNameOnCluster(string $name): void
    {
        $key = 'queues:{critical}';
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->andReturnTrue();
        $connection->shouldReceive('eval')
            ->twice()
            ->with(LuaScripts::migrateExpiredJobs(), 3, $key . ':delayed', $key, $key . ':notify', m::type('int'), m::type('int'))
            ->andReturn([]);
        $connection->shouldReceive('eval')
            ->once()
            ->with(LuaScripts::migrateExpiredJobs(), 3, $key . ':reserved', $key, $key . ':notify', m::type('int'), m::type('int'))
            ->andReturn([]);
        $connection->shouldReceive('eval')
            ->once()
            ->with(LuaScripts::pop(), 3, $key, $key . ':reserved', $key . ':notify', m::type('int'))
            ->andReturn([]);

        $queues = [];
        $queue = $this->queueRecordingMigrations($connection, $queues);

        $this->assertNull($queue->pop($name));

        $queue->migrateExpiredJobs($key . ':delayed', $key);

        $this->assertSame([$name, $name, '{critical}'], $queues);
    }

    public function testConcurrentPopsReportTheirOwnQueueNames(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->andReturnTrue();
        $connection->shouldReceive('eval')->andReturnUsing(function (): array {
            usleep(5000);

            return [];
        });

        $queues = [];
        $queue = $this->queueRecordingMigrations($connection, $queues);

        parallel([
            fn (): ?Job => $queue->pop('critical'),
            fn (): ?Job => $queue->pop('low'),
        ]);

        $this->assertEqualsCanonicalizing(['critical', 'critical', 'low', 'low'], $queues);
    }

    public function testFailedPopDoesNotLeakItsQueueNameIntoLaterMigrations(): void
    {
        $exception = new RedisException('Connection lost');
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->andReturnFalse();
        $connection->shouldReceive('eval')
            ->once()
            ->with(LuaScripts::migrateExpiredJobs(), 3, 'queues:critical:delayed', 'queues:critical', 'queues:critical:notify', m::type('int'), m::type('int'))
            ->andThrow($exception);
        $connection->shouldReceive('eval')
            ->once()
            ->with(LuaScripts::migrateExpiredJobs(), 3, 'queues:low:delayed', 'queues:low', 'queues:low:notify', m::type('int'), m::type('int'))
            ->andReturn([]);

        $queues = [];
        $queue = $this->queueRecordingMigrations($connection, $queues);

        try {
            $queue->pop('critical');
            $this->fail('The pop should have failed.');
        } catch (RedisException $caught) {
            $this->assertSame($exception, $caught);
        }

        $queue->migrateExpiredJobs('queues:low:delayed', 'queues:low');

        $this->assertSame(['low'], $queues);
    }

    /**
     * Create a Horizon queue that records the queue name of each migration event.
     */
    private function queueRecordingMigrations(RedisProxy $connection, array &$queues): RedisQueue
    {
        $redis = m::mock(Factory::class);
        $redis->shouldReceive('connection')->with('default')->andReturn($connection);

        $container = new Container;
        $events = new Dispatcher($container);
        $container->instance(DispatcherContract::class, $events);
        $events->listen(JobsMigrated::class, function (JobsMigrated $event) use (&$queues): void {
            $queues[] = $event->queue;
        });

        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer($container);
        $queue->setConnectionName('redis');

        return $queue;
    }
}
