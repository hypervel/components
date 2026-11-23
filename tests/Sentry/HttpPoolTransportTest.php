<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\HttpTransportPool;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Options;
use Sentry\Transport\HttpTransport;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Swoole\Coroutine\Channel;

class HttpPoolTransportTest extends TestCase
{
    use VerifiesDoubles;

    public function testAcquisitionTimeoutReturnsSkippedWithoutReleasingTheBorrowedTransport(): void
    {
        $pool = Double::for(new HttpTransportPool(
            new ClientBuilder,
            PoolOptions::fromArray(['max_objects' => 1, 'wait_timeout' => 0.001]),
        ))->passthru();
        $pool->expects('createObject')->returns(Double::for(HttpTransport::class)->strict());
        $borrowed = $pool->borrow();
        $transport = new HttpPoolTransport($pool, $this->optionsWithDsn());

        try {
            $result = $transport->send(Event::createEvent());

            $this->assertSame(ResultStatus::skipped(), $result->getStatus());
            $this->assertSame(1, $pool->getBorrowedCount());
        } finally {
            $pool->release($borrowed);
            $transport->shutdown();
        }
    }

    public function testAcceptedSendReturnsItsEventAndReleasesTransportAfterCompletion(): void
    {
        $httpTransport = Double::for(HttpTransport::class)->strict();
        $httpTransport->expects('send')
            ->returns(new Result(ResultStatus::success()));

        $pool = Double::for(HttpTransportPool::class)->strict();
        $pool->expects('borrow')
            ->returns($httpTransport);
        $pool->expects('release')
            ->with(Argument::same($httpTransport));

        $transport = new HttpPoolTransport($pool, $this->optionsWithDsn());

        $event = Event::createEvent();
        $result = $transport->send($event);

        $this->assertSame(ResultStatus::success(), $result->getStatus());
        $this->assertSame($event, $result->getEvent());
        $this->assertSame(ResultStatus::success(), $transport->close()->getStatus());
    }

    public function testClosedPoolReturnsSkipped(): void
    {
        $pool = new HttpTransportPool(new ClientBuilder, PoolOptions::fromArray([]));
        $pool->close();

        $result = (new HttpPoolTransport($pool, $this->optionsWithDsn()))->send(Event::createEvent());

        $this->assertSame(ResultStatus::skipped(), $result->getStatus());
    }

    public function testSendWithoutAnEndpointSkipsWithoutBorrowingOrStartingACoroutine(): void
    {
        $createdCoroutines = 0;
        Coroutine::afterCreated(static function () use (&$createdCoroutines): void {
            ++$createdCoroutines;
        });
        $pool = Double::for(HttpTransportPool::class)->strict();
        $pool->expects('borrow')->never();
        $transport = new HttpPoolTransport($pool, new Options);
        $event = Event::createEvent();

        $result = $transport->send($event);

        $this->assertSame(ResultStatus::skipped(), $result->getStatus());
        $this->assertSame($event, $result->getEvent());
        $this->assertSame(0, $createdCoroutines);
        $this->assertSame(ResultStatus::success(), $transport->close()->getStatus());
    }

    public function testSpotlightOnlySendIsDeliveredAsynchronously(): void
    {
        $started = new Channel(1);
        $finish = new Channel(1);
        $httpTransport = Double::for(HttpTransport::class)->strict();
        $httpTransport->expects('send')->resolves(
            static function () use ($started, $finish): Result {
                $started->push(true);
                $finish->pop(1.0);

                return new Result(ResultStatus::success());
            }
        );
        $pool = Double::for(HttpTransportPool::class)->strict();
        $pool->expects('borrow')->returns($httpTransport);
        $pool->expects('release')->with(Argument::same($httpTransport));
        $transport = new HttpPoolTransport($pool, new Options(['spotlight' => true]));

        $result = $transport->send(Event::createEvent());

        try {
            $this->assertSame(ResultStatus::success(), $result->getStatus());
            $this->assertTrue($started->pop(1.0));
            $this->assertSame(ResultStatus::unknown(), $transport->close()->getStatus());
        } finally {
            $finish->push(true);
            $drained = $transport->close(1);
        }

        $this->assertSame(ResultStatus::success(), $drained->getStatus());
    }

    public function testTransportCreationFailurePropagatesUnchanged(): void
    {
        $failure = new RuntimeException('Transport creation failed.');
        $pool = Double::for(new HttpTransportPool(new ClientBuilder, PoolOptions::fromArray([])))->passthru();
        $pool->expects('createObject')->throws($failure);
        $transport = new HttpPoolTransport($pool, $this->optionsWithDsn());
        $caught = null;

        try {
            $transport->send(Event::createEvent());
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            $transport->shutdown();
        }

        $this->assertSame($failure, $caught);
        $this->assertSame(0, $pool->getManagedCount());
    }

    public function testDeliveryMarkerIsAvailableToChildStartupHooks(): void
    {
        $marker = new Channel(1);
        Coroutine::afterCreated(static function () use ($marker): void {
            $marker->push(CoroutineContext::get(HttpPoolTransport::DELIVERY_CONTEXT_KEY));
        });
        $httpTransport = Double::for(HttpTransport::class)->strict();
        $httpTransport->expects('send')
            ->returns(new Result(ResultStatus::success()));
        $pool = Double::for(HttpTransportPool::class)->strict();
        $pool->expects('borrow')->returns($httpTransport);
        $pool->expects('release')->with(Argument::same($httpTransport));
        $transport = new HttpPoolTransport($pool, $this->optionsWithDsn());

        $transport->send(Event::createEvent());

        $this->assertTrue($marker->pop(1.0));
        $this->assertSame(ResultStatus::success(), $transport->close(1)->getStatus());
    }

    public function testUnexpectedChildFailureDiscardsTransport(): void
    {
        $httpTransport = Double::for(HttpTransport::class)->strict();
        $httpTransport->expects('send')
            ->throws(new RuntimeException('Send failed'));

        $pool = Double::for(HttpTransportPool::class)->strict();
        $pool->expects('borrow')
            ->returns($httpTransport);
        $pool->expects('discard')
            ->with(Argument::same($httpTransport));

        $transport = new HttpPoolTransport($pool, $this->optionsWithDsn());

        $event = Event::createEvent();
        $result = $transport->send($event);

        $this->assertSame(ResultStatus::success(), $result->getStatus());
        $this->assertSame($event, $result->getEvent());

        $transport->close();
    }

    public function testMixedSuccessAndFailureFinalizesCorrectly(): void
    {
        $httpTransport1 = Double::for(HttpTransport::class)->strict();
        $httpTransport1->expects('send')
            ->returns(new Result(ResultStatus::success()));

        $httpTransport2 = Double::for(HttpTransport::class)->strict();
        $httpTransport2->expects('send')
            ->throws(new RuntimeException('Send failed'));

        $httpTransport3 = Double::for(HttpTransport::class)->strict();
        $httpTransport3->expects('send')
            ->returns(new Result(ResultStatus::success()));

        $pool = Double::for(HttpTransportPool::class)->strict();
        $pool->expects('borrow')
            ->times(3)
            ->returns($httpTransport1, $httpTransport2, $httpTransport3);
        // transport2 is discarded immediately on exception.
        $pool->expects('discard')
            ->with(Argument::same($httpTransport2));
        $pool->expects('release')
            ->with(Argument::same($httpTransport1));
        $pool->expects('release')
            ->with(Argument::same($httpTransport3));

        $transport = new HttpPoolTransport($pool, $this->optionsWithDsn());

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

        $first = Double::for(HttpTransport::class)->strict();
        $first->expects('send')->resolves(
            static function () use ($firstStarted, $releaseFirst): Result {
                $firstStarted->push(true);
                $releaseFirst->pop();

                return new Result(ResultStatus::success());
            }
        );
        $second = Double::for(HttpTransport::class)->strict();
        $second->expects('send')->resolves(
            static function () use ($secondStarted, $releaseSecond): Result {
                $secondStarted->push(true);
                $releaseSecond->pop();

                return new Result(ResultStatus::success());
            }
        );

        $pool = Double::for(HttpTransportPool::class)->strict();
        $pool->expects('borrow')->times(2)->returns($first, $second);
        $pool->expects('release')->with(Argument::same($first));
        $pool->expects('release')->with(Argument::same($second));

        $transport = new HttpPoolTransport($pool, $this->optionsWithDsn());

        try {
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
        } finally {
            // Release both sends even after a failed assertion. Any earlier release has already been consumed, so neither push blocks.
            $releaseFirst->push(true);
            $releaseSecond->push(true);
            $drained = $transport->close(1);
        }

        $this->assertSame(ResultStatus::success(), $drained->getStatus());
    }

    public function testCoroutineCreationFailureBalancesTheGenerationAndReleasesTheTransport(): void
    {
        $httpTransport = Double::for(HttpTransport::class)->strict();
        $httpTransport->expects('send')->never();

        $pool = Double::for(HttpTransportPool::class)->strict();
        $pool->expects('borrow')->returns($httpTransport);
        $pool->expects('release')->with(Argument::same($httpTransport));

        $transport = new FailingCoroutineHttpPoolTransport($pool, $this->optionsWithDsn());
        $result = $transport->send(Event::createEvent());

        $this->assertSame(ResultStatus::failed(), $result->getStatus());
        $this->assertSame(ResultStatus::success(), $transport->close()->getStatus());
    }

    public function testStartupCancellationReleasesAnUntouchedTransport(): void
    {
        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        Container::getInstance()->instance(ExceptionHandlerContract::class, $handler);
        $httpTransport = Double::for(HttpTransport::class)->strict();
        $httpTransport->expects('send')->never();
        $pool = Double::for(HttpTransportPool::class)->strict();
        $pool->expects('borrow')->returns($httpTransport);
        $pool->expects('release')->with(Argument::same($httpTransport));
        $pool->expects('discard')->never();
        $transport = new HttpPoolTransport($pool, $this->optionsWithDsn());
        $hookFailure = new RuntimeException('The startup hook failed.');
        $reportStarted = new Channel(1);
        $releaseReport = new Channel(1);
        $childCoroutineId = null;

        $handler->expects('report')
            ->with(Argument::same($hookFailure))
            ->resolves(static function () use ($reportStarted, $releaseReport, &$childCoroutineId): void {
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
        $pool = Double::for(HttpTransportPool::class)->strict();
        $pool->expects('close');

        (new HttpPoolTransport($pool, $this->optionsWithDsn()))->shutdown();
    }

    /**
     * Get options with a DSN, so sends use the pool.
     */
    private function optionsWithDsn(): Options
    {
        return new Options(['dsn' => 'https://public@example.com/1']);
    }
}

class FailingCoroutineHttpPoolTransport extends HttpPoolTransport
{
    /**
     * Fail to create the delivery coroutine.
     */
    protected function createCoroutine(callable $callback, Closure $wrapper): void
    {
        throw new RuntimeException('Unable to create coroutine.');
    }
}
