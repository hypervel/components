<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Events\Dispatcher;
use Hypervel\Queue\Capsule\Manager;
use Hypervel\Queue\NullQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Tests\TestCase;

class QueueCapsuleManagerTest extends TestCase
{
    public function testStandaloneCapsuleResolvesConnectionsAndExecutesAJob(): void
    {
        $capsule = new Manager;
        $capsule->addConnection(['driver' => 'sync']);
        $capsule->addConnection(['driver' => 'null'], 'discard');
        $job = new QueueCapsuleTestJob;
        $capsule->getContainer()->instance(QueueCapsuleTestJob::class, $job);

        $capsule->getConnection()->push(QueueCapsuleTestJob::class, 'payload', 'emails');

        $this->assertSame(['payload', 'default', 'emails'], $job->received);
        $this->assertInstanceOf(NullQueue::class, $capsule->getConnection('discard'));
    }

    public function testFailoverUsesTheCapsulesOwnManager(): void
    {
        $container = new Container;
        $container->instance(DispatcherContract::class, new Dispatcher($container));
        $container->instance('queue', new QueueManager(new Container));
        $capsule = new Manager($container);
        $capsule->addConnection(['driver' => 'failover', 'connections' => ['discard']]);
        $capsule->addConnection(['driver' => 'null'], 'discard');

        $queue = $capsule->getConnection();

        $this->assertSame($capsule->getQueueManager(), $queue->manager);
        $this->assertSame(0, $queue->size());
    }
}

class QueueCapsuleTestJob
{
    public array $received = [];

    /**
     * Record the delivered payload and queue identifiers.
     */
    public function fire(Job $job, mixed $data): void
    {
        $this->received = [$data, $job->getConnectionName(), $job->getQueue()];
    }
}
