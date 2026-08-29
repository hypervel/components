<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\Pool;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Sentry\Event;
use Sentry\Transport\HttpTransport;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;

class HttpPoolTransportNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testAcceptedSendCompletesBeforeReturningOutsideACoroutine(): void
    {
        $completed = false;
        $httpTransport = m::mock(HttpTransport::class);
        $httpTransport->shouldReceive('send')
            ->once()
            ->andReturnUsing(function () use (&$completed): Result {
                usleep(5_000);
                $completed = true;

                return new Result(ResultStatus::success());
            });
        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')->once()->andReturn($httpTransport);
        $pool->shouldReceive('release')->once()->with($httpTransport);

        $result = (new HttpPoolTransport($pool))->send(Event::createEvent());

        $this->assertTrue($completed);
        $this->assertSame(ResultStatus::success(), $result->getStatus());
    }

    public function testFailedSendDiscardsTheTransportBeforeReturningOutsideACoroutine(): void
    {
        $httpTransport = m::mock(HttpTransport::class);
        $httpTransport->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Send failed'));
        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')->once()->andReturn($httpTransport);
        $pool->shouldReceive('discard')->once()->with($httpTransport);

        $result = (new HttpPoolTransport($pool))->send(Event::createEvent());

        $this->assertSame(ResultStatus::success(), $result->getStatus());
    }
}
