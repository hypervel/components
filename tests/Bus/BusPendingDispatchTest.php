<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Foundation\Bus\PendingDispatch;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionClass;
use stdClass;

class PendingDispatchWithoutDestructor extends PendingDispatch
{
    public function __destruct()
    {
        // Prevent the job from being dispatched
    }
}

/**
 * @internal
 * @coversNothing
 */
class BusPendingDispatchTest extends TestCase
{
    protected $job;

    /**
     * @var PendingDispatchWithoutDestructor
     */
    protected $pendingDispatch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->job = m::mock(stdClass::class);
        $this->pendingDispatch = new PendingDispatchWithoutDestructor($this->job);
    }

    public function testOnConnection()
    {
        $this->job->shouldReceive('onConnection')->once()->with('test-connection');
        $this->pendingDispatch->onConnection('test-connection');
    }

    public function testOnQueue()
    {
        $this->job->shouldReceive('onQueue')->once()->with('test-queue');
        $this->pendingDispatch->onQueue('test-queue');
    }

    public function testOnGroup()
    {
        $this->job->shouldReceive('onGroup')->once()->with('test-group');
        $this->pendingDispatch->onGroup('test-group');
    }

    public function testWithDeduplicator()
    {
        $deduplicator = fn () => 'id';
        $this->job->shouldReceive('withDeduplicator')->once()->with($deduplicator);
        $this->pendingDispatch->withDeduplicator($deduplicator);
    }

    public function testAllOnConnection()
    {
        $this->job->shouldReceive('allOnConnection')->once()->with('test-connection');
        $this->pendingDispatch->allOnConnection('test-connection');
    }

    public function testAllOnQueue()
    {
        $this->job->shouldReceive('allOnQueue')->once()->with('test-queue');
        $this->pendingDispatch->allOnQueue('test-queue');
    }

    public function testDelay()
    {
        $this->job->shouldReceive('delay')->once()->with(60);
        $this->pendingDispatch->delay(60);
    }

    public function testWithoutDelay()
    {
        $this->job->shouldReceive('withoutDelay')->once();
        $this->pendingDispatch->withoutDelay();
    }

    public function testAfterCommit()
    {
        $this->job->shouldReceive('afterCommit')->once();
        $this->pendingDispatch->afterCommit();
    }

    public function testBeforeCommit()
    {
        $this->job->shouldReceive('beforeCommit')->once();
        $this->pendingDispatch->beforeCommit();
    }

    public function testChain()
    {
        $chain = [new stdClass()];
        $this->job->shouldReceive('chain')->once()->with($chain);
        $this->pendingDispatch->chain($chain);
    }

    public function testAfterResponse()
    {
        $this->pendingDispatch->afterResponse();
        $this->assertTrue(
            (new ReflectionClass($this->pendingDispatch))->getProperty('afterResponse')->getValue($this->pendingDispatch)
        );
    }

    public function testGetJob()
    {
        $this->assertSame($this->job, $this->pendingDispatch->getJob());
    }

    public function testDynamicallyProxyMethods()
    {
        $newJob = m::mock(stdClass::class);
        $this->job->shouldReceive('appendToChain')->once()->with($newJob);
        $this->pendingDispatch->appendToChain($newJob);
    }
}
