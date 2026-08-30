<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\Pool;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Sentry\Event;
use Sentry\Transport\HttpTransport;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Swoole\Coroutine\Channel;

class HttpPoolTransportTest extends TestCase
{
    public function testBackpressureReturnsSkippedWhenPoolExhausted(): void
    {
        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')
            ->once()
            ->andThrow(new RuntimeException('Object pool exhausted. Cannot create new object before wait_timeout.'));

        $transport = new HttpPoolTransport($pool);

        $result = $transport->send(Event::createEvent());

        $this->assertSame(ResultStatus::skipped(), $result->getStatus());
    }

    public function testBackpressureDoesNotBlockOnPoolExhaustion(): void
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

    public function testAcceptedSendReturnsItsEventAndReleasesTransportAfterCompletion(): void
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

        $event = Event::createEvent();
        $result = $transport->send($event);

        $this->assertSame(ResultStatus::success(), $result->getStatus());
        $this->assertSame($event, $result->getEvent());
        $this->assertSame(ResultStatus::success(), $transport->close()->getStatus());
    }

    public function testMultipleSendsThenCloseReleasesAllTransports(): void
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

    public function testThreeSendsThenCloseReleasesAllTransports(): void
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

    public function testUnexpectedChildFailureDiscardsTransport(): void
    {
        $httpTransport = m::mock(HttpTransport::class);
        $httpTransport->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Send failed'));

        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')
            ->once()
            ->andReturn($httpTransport);
        $pool->shouldReceive('discard')
            ->once()
            ->with($httpTransport);

        $transport = new HttpPoolTransport($pool);

        $event = Event::createEvent();
        $result = $transport->send($event);

        $this->assertSame(ResultStatus::success(), $result->getStatus());
        $this->assertSame($event, $result->getEvent());

        $transport->close();
    }

    public function testFailedTransportIsReplacedOnTheNextBorrow(): void
    {
        $failed = m::mock(HttpTransport::class);
        $failed->shouldReceive('send')->once()->andThrow(new RuntimeException('Send failed'));
        $replacement = m::mock(HttpTransport::class);
        $replacement->shouldReceive('send')
            ->once()
            ->andReturn(new Result(ResultStatus::success()));
        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')->twice()->andReturn($failed, $replacement);
        $pool->shouldReceive('discard')->once()->with($failed);
        $pool->shouldReceive('release')->once()->with($replacement);
        $transport = new HttpPoolTransport($pool);

        $this->assertSame(ResultStatus::success(), $transport->send(Event::createEvent())->getStatus());
        $this->assertSame(ResultStatus::success(), $transport->send(Event::createEvent())->getStatus());
        $transport->close();
    }

    public function testMixedSuccessAndFailureFinalizesCorrectly(): void
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
        // transport2 is discarded immediately on exception.
        $pool->shouldReceive('discard')
            ->once()
            ->with($httpTransport2);
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

    public function testPositiveCloseWaitsOnlyForItsCapturedGeneration(): void
    {
        $firstStarted = new Channel(1);
        $secondStarted = new Channel(1);
        $releaseFirst = new Channel(1);
        $releaseSecond = new Channel(1);
        $drainResult = new Channel(1);

        $first = m::mock(HttpTransport::class);
        $first->shouldReceive('send')->once()->andReturnUsing(
            static function () use ($firstStarted, $releaseFirst): Result {
                $firstStarted->push(true);
                $releaseFirst->pop();

                return new Result(ResultStatus::success());
            }
        );
        $second = m::mock(HttpTransport::class);
        $second->shouldReceive('send')->once()->andReturnUsing(
            static function () use ($secondStarted, $releaseSecond): Result {
                $secondStarted->push(true);
                $releaseSecond->pop();

                return new Result(ResultStatus::success());
            }
        );

        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')->twice()->andReturn($first, $second);
        $pool->shouldReceive('release')->once()->with($first);
        $pool->shouldReceive('release')->once()->with($second);

        $transport = new HttpPoolTransport($pool);
        $transport->send(Event::createEvent());
        $this->assertTrue($firstStarted->pop(1.0));

        Coroutine::create(static function () use ($drainResult, $transport): void {
            $drainResult->push($transport->close(1));
        });

        $transport->send(Event::createEvent());
        $this->assertTrue($secondStarted->pop(1.0));

        $releaseFirst->push(true);
        $firstResult = $drainResult->pop(1.0);

        $this->assertInstanceOf(Result::class, $firstResult);
        $this->assertSame(ResultStatus::success(), $firstResult->getStatus());
        $this->assertSame(ResultStatus::unknown(), $transport->close()->getStatus());

        $releaseSecond->push(true);
        $this->assertSame(ResultStatus::success(), $transport->close(1)->getStatus());
    }

    public function testCoroutineCreationFailureBalancesTheGenerationAndReleasesTheTransport(): void
    {
        $httpTransport = m::mock(HttpTransport::class);
        $httpTransport->shouldNotReceive('send');

        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')->once()->andReturn($httpTransport);
        $pool->shouldReceive('release')->once()->with($httpTransport);

        $transport = new FailingCoroutineHttpPoolTransport($pool);
        $result = $transport->send(Event::createEvent());

        $this->assertSame(ResultStatus::failed(), $result->getStatus());
        $this->assertSame(ResultStatus::success(), $transport->close()->getStatus());
    }

    public function testStartupCancellationReleasesAnUntouchedTransport(): void
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        Container::getInstance()->instance(ExceptionHandlerContract::class, $handler);
        $httpTransport = m::mock(HttpTransport::class);
        $httpTransport->shouldNotReceive('send');
        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')->once()->andReturn($httpTransport);
        $pool->shouldReceive('release')->once()->with($httpTransport);
        $pool->shouldNotReceive('discard');
        $transport = new HttpPoolTransport($pool);
        $hookFailure = new RuntimeException('The startup hook failed.');
        $reportStarted = new Channel(1);
        $releaseReport = new Channel(1);
        $childCoroutineId = null;

        $handler->shouldReceive('report')
            ->once()
            ->with($hookFailure)
            ->andReturnUsing(static function () use ($reportStarted, $releaseReport, &$childCoroutineId): void {
                $childCoroutineId = EngineCoroutine::id();
                $reportStarted->push(true);
                $releaseReport->pop();
            });

        Coroutine::afterCreated(static function () use ($hookFailure): void {
            throw $hookFailure;
        });

        $result = $transport->send(Event::createEvent());

        try {
            $this->assertSame(ResultStatus::success(), $result->getStatus());
            $this->assertSame(ResultStatus::unknown(), $transport->close()->getStatus());
            $this->assertTrue($reportStarted->pop(1));
            $this->assertIsInt($childCoroutineId);
            $this->assertTrue(EngineCoroutine::cancelById($childCoroutineId, throwException: true));
            $this->assertFalse(Coroutine::exists($childCoroutineId));
            $this->assertSame(ResultStatus::success(), $transport->close()->getStatus());
        } finally {
            $releaseReport->push(true, 0.001);

            if (is_int($childCoroutineId) && Coroutine::exists($childCoroutineId)) {
                EngineCoroutine::cancelById($childCoroutineId, throwException: true);
                Coroutine::join([$childCoroutineId], 1);
            }
        }
    }

    public function testShutdownClosesThePool(): void
    {
        $pool = m::mock(Pool::class);
        $pool->shouldReceive('close')->once();

        (new HttpPoolTransport($pool))->shutdown();
    }

    public function testCloseWithNoSendsDoesNothing(): void
    {
        $pool = m::mock(Pool::class);
        $pool->shouldNotReceive('release');

        $transport = new HttpPoolTransport($pool);

        $result = $transport->close();

        $this->assertSame(ResultStatus::success(), $result->getStatus());
    }

    public function testRepeatedCloseDoesNotReleaseACompletedSendAgain(): void
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
        $transport->close();
    }

    public function testChildReleasesTransportWithoutARequestClose(): void
    {
        $httpTransport = m::mock(HttpTransport::class);
        $httpTransport->shouldReceive('send')
            ->once()
            ->andReturn(new Result(ResultStatus::success()));

        $released = new Channel(1);

        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')
            ->once()
            ->andReturn($httpTransport);
        $pool->shouldReceive('release')
            ->once()
            ->with($httpTransport)
            ->andReturnUsing(function () use ($released): void {
                $released->push(true);
            });

        $transport = new HttpPoolTransport($pool);

        Coroutine::create(function () use ($transport): void {
            $transport->send(Event::createEvent());
        });

        $wasReleased = $released->pop(1.0);

        $this->assertTrue($wasReleased);
    }

    public function testCloseDoesNotReleaseAnAlreadyCompletedSendAgain(): void
    {
        $httpTransport = m::mock(HttpTransport::class);
        $httpTransport->shouldReceive('send')
            ->once()
            ->andReturn(new Result(ResultStatus::success()));

        $releaseCount = 0;

        $pool = m::mock(Pool::class);
        $pool->shouldReceive('get')
            ->once()
            ->andReturn($httpTransport);
        $pool->shouldReceive('release')
            ->with($httpTransport)
            ->andReturnUsing(function () use (&$releaseCount): void {
                ++$releaseCount;
            });

        $transport = new HttpPoolTransport($pool);

        $done = new Channel(1);

        Coroutine::create(function () use ($transport, $done): void {
            $transport->send(Event::createEvent());
            $transport->close();
            $done->push(true);
        });

        $done->pop(1.0);

        $this->assertSame(1, $releaseCount);
    }
}

class FailingCoroutineHttpPoolTransport extends HttpPoolTransport
{
    protected function createCoroutine(callable $callback, Closure $wrapper): void
    {
        throw new RuntimeException('Unable to create coroutine.');
    }
}
