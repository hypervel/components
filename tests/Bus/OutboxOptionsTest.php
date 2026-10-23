<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\Batchable;
use Hypervel\Bus\PendingBatch;
use Hypervel\Bus\Queueable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Foundation\Bus\PendingDispatch;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;

class OutboxOptionsTest extends TestCase
{
    public function testUnusedOutboxPropertiesAreNotSerialized(): void
    {
        foreach ([new OutboxQueueableJob, new OutboxBatchableJob, new OutboxCombinedJob] as $job) {
            $this->assertStringNotContainsString('viaOutbox', serialize($job));
            $this->assertStringNotContainsString('outboxDatabase', serialize($job));

            $this->assertSame($job, $job->onOutboxDatabase(OutboxDatabase::Billing));
            $this->assertTrue($job->viaOutbox);
            $this->assertSame('billing', $job->outboxDatabase);

            $restored = unserialize(serialize($job));
            $this->assertTrue($restored->viaOutbox);
            $this->assertSame('billing', $restored->outboxDatabase);

            $this->assertSame($job, $job->withoutOutbox());
            $this->assertFalse($job->viaOutbox);
            $this->assertNull($job->outboxDatabase);
            $this->assertSame($job, $job->viaOutbox());
            $this->assertTrue($job->viaOutbox);
            $this->assertNull($job->outboxDatabase);
        }
    }

    public function testPendingDispatchForwardsOutboxOptions(): void
    {
        $job = new OutboxQueueableJob;
        $dispatcher = m::mock(Dispatcher::class);
        Container::getInstance()->instance(Dispatcher::class, $dispatcher);
        $dispatcher->expects('dispatch')->with($job)->andReturnNull();

        $pending = new PendingDispatch($job);
        $this->assertSame($pending, $pending->onOutboxDatabase('billing'));
        $this->assertSame('billing', $job->outboxDatabase);
        $this->assertSame($pending, $pending->withoutOutbox());
        $this->assertFalse($job->viaOutbox);
        $this->assertNull($job->outboxDatabase);
        $this->assertSame($pending, $pending->viaOutbox());
        $this->assertTrue($job->viaOutbox);

        unset($pending);
    }

    public function testPendingBatchStoresOnlyOutboxDefaults(): void
    {
        $batch = new PendingBatch(Container::getInstance(), new Collection);
        $this->assertSame($batch, $batch->onOutboxDatabase(OutboxDatabase::Billing));
        $this->assertSame(['outboxDatabase' => 'billing', 'viaOutbox' => true], $batch->options);
        $this->assertSame($batch, $batch->withoutOutbox());
        $this->assertSame(['viaOutbox' => false], $batch->options);
        $this->assertSame($batch, $batch->viaOutbox());
        $this->assertSame(['viaOutbox' => true], $batch->options);
    }

    public function testEmptyDatabaseIsRejectedWithoutChangingSelection(): void
    {
        foreach ([new OutboxQueueableJob, new PendingBatch(Container::getInstance(), new Collection)] as $target) {
            try {
                $target->onOutboxDatabase('');
                $this->fail('An empty owner must not select outbox delivery.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('The outbox database connection must not be empty.', $exception->getMessage());
            }

            $this->assertFalse(isset($target->viaOutbox));
        }
    }
}

class OutboxQueueableJob
{
    use Queueable;
}

class OutboxBatchableJob
{
    use Batchable;
}

class OutboxCombinedJob
{
    use Batchable;
    use Queueable;
}

enum OutboxDatabase: string
{
    case Billing = 'billing';
}
