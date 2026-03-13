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

/**
 * @internal
 * @coversNothing
 */
class HttpPoolTransportTest extends TestCase
{
    public function testBackpressureReturnsSkippedWhenPoolExhausted()
    {
        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')
            ->once()
            ->andThrow(new RuntimeException('Object pool exhausted. Cannot create new object before wait_timeout.'));

        $transport = new HttpPoolTransport($pool);

        $result = $transport->send(Event::createEvent());

        $this->assertSame(ResultStatus::skipped(), $result->getStatus());
    }

    public function testBackpressureDoesNotBlockOnPoolExhaustion()
    {
        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')
            ->once()
            ->andThrow(new RuntimeException('Object pool exhausted.'));

        $transport = new HttpPoolTransport($pool);

        // Should return immediately without blocking — no exception thrown
        $result = $transport->send(Event::createEvent());

        $this->assertSame(ResultStatus::skipped(), $result->getStatus());
    }

    public function testSingleSendThenCloseReleasesTransport()
    {
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

        $transport->send(Event::createEvent());
        $transport->close();
    }

    public function testMultipleSendsThenCloseReleasesAllTransports()
    {
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

        $transport->send(Event::createEvent());
        $transport->send(Event::createEvent());
        $transport->close();
    }

    public function testThreeSendsThenCloseReleasesAllTransports()
    {
        $httpTransports = [];
        for ($i = 0; $i < 3; ++$i) {
            $httpTransports[$i] = m::mock(HttpTransport::class);
            $httpTransports[$i]->shouldReceive('send')
                ->once()
                ->andReturn(new Result(ResultStatus::success()));
        }

        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')
            ->times(3)
            ->andReturn($httpTransports[0], $httpTransports[1], $httpTransports[2]);
        foreach ($httpTransports as $httpTransport) {
            $pool->shouldReceive('release')
                ->once()
                ->with($httpTransport);
        }

        $transport = new HttpPoolTransport($pool);

        $transport->send(Event::createEvent());
        $transport->send(Event::createEvent());
        $transport->send(Event::createEvent());
        $transport->close();
    }

    public function testSendExceptionReleasesTransportImmediately()
    {
        $httpTransport = m::mock(HttpTransport::class);
        $httpTransport->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Send failed'));

        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')
            ->once()
            ->andReturn($httpTransport);
        // Released immediately on exception, not tracked for close()
        $pool->shouldReceive('release')
            ->once()
            ->with($httpTransport);

        $transport = new HttpPoolTransport($pool);

        $result = $transport->send(Event::createEvent());

        $this->assertSame(ResultStatus::failed(), $result->getStatus());

        // close() should not try to release again — already released
        $transport->close();
    }

    public function testMixedSuccessAndFailureReleasesCorrectly()
    {
        $httpTransport1 = m::mock(HttpTransport::class);
        $httpTransport1->shouldReceive('send')
            ->once()
            ->andReturn(new Result(ResultStatus::success()));

        $httpTransport2 = m::mock(HttpTransport::class);
        $httpTransport2->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Send failed'));

        $httpTransport3 = m::mock(HttpTransport::class);
        $httpTransport3->shouldReceive('send')
            ->once()
            ->andReturn(new Result(ResultStatus::success()));

        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')
            ->times(3)
            ->andReturn($httpTransport1, $httpTransport2, $httpTransport3);
        // transport2 released immediately on exception
        $pool->shouldReceive('release')
            ->once()
            ->with($httpTransport2);
        // transport1 and transport3 released on close()
        $pool->shouldReceive('release')
            ->once()
            ->with($httpTransport1);
        $pool->shouldReceive('release')
            ->once()
            ->with($httpTransport3);

        $transport = new HttpPoolTransport($pool);

        $transport->send(Event::createEvent());
        $transport->send(Event::createEvent());
        $transport->send(Event::createEvent());
        $transport->close();
    }

    public function testCloseWithNoSendsDoesNothing()
    {
        $pool = m::mock(Pool::class);
        // release should never be called
        $pool->shouldNotReceive('release');

        $transport = new HttpPoolTransport($pool);

        $result = $transport->close();

        $this->assertSame(ResultStatus::success(), $result->getStatus());
    }

    public function testCloseAfterCloseDoesNotDoubleRelease()
    {
        $httpTransport = m::mock(HttpTransport::class);
        $httpTransport->shouldReceive('send')
            ->once()
            ->andReturn(new Result(ResultStatus::success()));

        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')
            ->once()
            ->andReturn($httpTransport);
        // Should only be released once across both close() calls
        $pool->shouldReceive('release')
            ->once()
            ->with($httpTransport);

        $transport = new HttpPoolTransport($pool);

        $transport->send(Event::createEvent());
        $transport->close();
        $transport->close(); // Second close should be a no-op
    }
}
