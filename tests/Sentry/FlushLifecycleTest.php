<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Context\Context;
use Hypervel\Http\Request;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\Pool;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Sentry\Event;
use Sentry\Transport\HttpTransport;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;

/**
 * @internal
 * @coversNothing
 */
class FlushLifecycleTest extends TestCase
{
    public function testFlushReleasesAllTransportsCheckedOutDuringRequest()
    {
        // Simulate: two events sent during a request, then flush releases both
        $httpTransport1 = m::mock(HttpTransport::class);
        $httpTransport1->shouldReceive('send')
            ->once()
            ->andReturn(new Result(ResultStatus::success()));

        $httpTransport2 = m::mock(HttpTransport::class);
        $httpTransport2->shouldReceive('send')
            ->once()
            ->andReturn(new Result(ResultStatus::success()));

        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')
            ->twice()
            ->andReturn($httpTransport1, $httpTransport2);
        $pool->shouldReceive('release')
            ->once()
            ->with($httpTransport1);
        $pool->shouldReceive('release')
            ->once()
            ->with($httpTransport2);

        $transport = new HttpPoolTransport($pool);

        // Simulate sending two events during request handling
        $transport->send(Event::createEvent());
        $transport->send(Event::createEvent());

        // Verify transports are tracked in context
        $tracked = Context::get('__sentry.transports', []);
        $this->assertCount(2, $tracked);

        // Simulate what flush does: client->flush() -> transport->close()
        $transport->close();

        // Verify context is cleaned up
        $tracked = Context::get('__sentry.transports', []);
        $this->assertCount(0, $tracked);
    }

    public function testTransportCloseReleasesCheckedOutTransport()
    {
        // Verify that close() releases a single checked-out transport back to the pool
        $httpTransport = m::mock(HttpTransport::class);
        $httpTransport->shouldReceive('send')
            ->once()
            ->andReturn(new Result(ResultStatus::success()));

        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')
            ->once()
            ->andReturn($httpTransport);
        $pool->shouldReceive('release')
            ->once()
            ->with($httpTransport);

        $transport = new HttpPoolTransport($pool);

        // Send an event (checks out a transport)
        $transport->send(Event::createEvent());

        $this->assertCount(1, Context::get('__sentry.transports', []));

        // close() releases the transport back to the pool
        $transport->close();

        // All transports should be released
        $this->assertCount(0, Context::get('__sentry.transports', []));
    }
}
