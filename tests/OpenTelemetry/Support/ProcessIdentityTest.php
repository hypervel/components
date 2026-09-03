<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Support;

use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\Tests\TestCase;

class ProcessIdentityTest extends TestCase
{
    public function testWorkerAndCliIdentitiesExposeStableResourceAttributes(): void
    {
        $event = ProcessIdentity::eventWorker(3);
        $task = ProcessIdentity::taskWorker(7);
        $cli = ProcessIdentity::cli();

        $this->assertSame('3', $event->stableId());
        $this->assertSame([
            'hypervel.worker.type' => 'event',
            'hypervel.worker.id' => 3,
        ], $event->resourceAttributes());
        $this->assertSame('7', $task->stableId());
        $this->assertSame('task', $task->resourceAttributes()['hypervel.worker.type']);
        $this->assertSame('0', $cli->stableId());
        $this->assertSame('cli', $cli->resourceAttributes()['hypervel.worker.type']);
    }

    public function testServerProcessIdentityIncludesConfiguredProcessMetadata(): void
    {
        $identity = ProcessIdentity::serverProcess(ProcessStub::class, 'relay', 2);

        $this->assertSame('relay.2', $identity->stableId());
        $this->assertSame([
            'hypervel.worker.type' => 'process',
            'hypervel.process.class' => ProcessStub::class,
            'hypervel.process.name' => 'relay',
            'hypervel.process.index' => 2,
        ], $identity->resourceAttributes());
    }
}

class ProcessStub
{
}
