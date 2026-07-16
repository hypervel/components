<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Bus\Queueable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Foundation\Bus\PendingChain;
use Hypervel\Tests\TestCase;
use Mockery as m;

class PendingChainTest extends TestCase
{
    public function testEnumConnectionAndQueueIdentifiersAreNormalized(): void
    {
        $chain = new PendingChain(new class {}, []);

        $chain
            ->onConnection(PendingChainIntegerIdentifier::Zero)
            ->onQueue(PendingChainUnitIdentifier::Primary);

        $this->assertSame('0', $chain->connection);
        $this->assertSame('Primary', $chain->queue);
    }

    public function testDispatchPreservesZeroIdentifiersOnTheChainAndFirstJob(): void
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->twice()->andReturnArg(0);
        Container::getInstance()->instance(Dispatcher::class, $dispatcher);

        $fromChain = (new PendingChain(new PendingChainTestJob, []))
            ->onConnection(PendingChainIntegerIdentifier::Zero)
            ->onQueue(PendingChainIntegerIdentifier::Zero)
            ->dispatch();

        $firstJob = (new PendingChainTestJob)->onConnection('0')->onQueue('0');
        $fromFirstJob = (new PendingChain($firstJob, []))
            ->onConnection('fallback-connection')
            ->onQueue('fallback-queue')
            ->dispatch();

        $this->assertSame('0', $fromChain->connection);
        $this->assertSame('0', $fromChain->queue);
        $this->assertSame('0', $fromFirstJob->connection);
        $this->assertSame('0', $fromFirstJob->queue);
    }

    public function testDispatchUsesChainIdentifiersForEmptyFirstJobIdentifiers(): void
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturnArg(0);
        Container::getInstance()->instance(Dispatcher::class, $dispatcher);

        $firstJob = (new PendingChainTestJob)->onConnection('')->onQueue('');

        $dispatched = (new PendingChain($firstJob, []))
            ->onConnection('fallback-connection')
            ->onQueue('fallback-queue')
            ->dispatch();

        $this->assertSame('fallback-connection', $dispatched->connection);
        $this->assertSame('fallback-queue', $dispatched->queue);
    }

    public function testDispatchIgnoresEmptyChainIdentifiers(): void
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturnArg(0);
        Container::getInstance()->instance(Dispatcher::class, $dispatcher);

        $firstJob = (new PendingChainTestJob)
            ->onConnection('job-connection')
            ->onQueue('job-queue');

        $dispatched = (new PendingChain($firstJob, []))
            ->onConnection('')
            ->onQueue('')
            ->dispatch();

        $this->assertSame('job-connection', $dispatched->connection);
        $this->assertSame('job-queue', $dispatched->queue);
        $this->assertNull($dispatched->chainConnection);
        $this->assertNull($dispatched->chainQueue);
    }
}

enum PendingChainIntegerIdentifier: int
{
    case Zero = 0;
}

enum PendingChainUnitIdentifier
{
    case Primary;
}

class PendingChainTestJob
{
    use Queueable;
}
