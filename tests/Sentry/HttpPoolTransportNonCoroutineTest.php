<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\Pool;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Sentry\Event;
use Sentry\Transport\HttpTransport;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;

class HttpPoolTransportNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testRootCoroutineOwnsAndReleasesTheTransport(): void
    {
        $httpTransport = m::mock(HttpTransport::class);
        $httpTransport->shouldReceive('send')
            ->once()
            ->andReturn(new Result(ResultStatus::success()));
        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')->once()->andReturn($httpTransport);
        $pool->shouldReceive('release')->once()->with($httpTransport);
        $transport = new HttpPoolTransport($pool);
        $event = Event::createEvent();

        $result = $transport->send($event);

        $this->assertSame(ResultStatus::success(), $result->getStatus());
        $this->assertSame($event, $result->getEvent());
        $this->assertSame(ResultStatus::success(), $transport->close()->getStatus());
    }
}
