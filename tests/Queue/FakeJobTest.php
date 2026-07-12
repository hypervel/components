<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Container\Container;
use Hypervel\Queue\Jobs\FakeJob;
use Hypervel\Queue\SyncQueue;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;

class FakeJobTest extends TestCase
{
    public function testDefaultIdentityIsFullyInitialized(): void
    {
        $job = new FakeJob;

        $this->assertSame('sync', $job->getConnectionName());
        $this->assertSame('default', $job->getQueue());
    }

    public function testIdentityCanBeSpecifiedForFocusedTests(): void
    {
        $job = new FakeJob(connectionName: 'redis', queue: 'critical');

        $this->assertSame('redis', $job->getConnectionName());
        $this->assertSame('critical', $job->getQueue());
    }

    public function testSyncQueueNormalizesANullQueueBeforeConstructingTheJob(): void
    {
        $queue = new SyncQueue;
        $queue->setContainer(new Container);
        $queue->setConnectionName('sync');

        $job = (new ClassInvoker($queue))->resolveJob('{}', null);

        $this->assertSame('sync', (new ClassInvoker($job))->queue);
    }
}
