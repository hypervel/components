<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Http;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Sentry\Http\FlushEventsMiddleware;
use Hypervel\Sentry\State\CoroutineRuntimeContextStorage;
use Hypervel\Sentry\State\RuntimeContextBoundary;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Sentry\ClientInterface;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Swoole\Coroutine\Channel;
use Symfony\Component\HttpFoundation\Response;

class FlushEventsMiddlewareTest extends TestCase
{
    public function testFlushIsDeferredUntilTheRequestCoroutineExits(): void
    {
        $handled = new Channel(1);
        $flushed = new Channel(1);
        $storage = new CoroutineRuntimeContextStorage;
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('getOptions')->once()->andReturn(new Options);
        $client->shouldReceive('flush')
            ->once()
            ->with(null)
            ->andReturnUsing(static function () use ($flushed): Result {
                $flushed->push(true);

                return new Result(ResultStatus::success());
            });
        $previousHub = SentrySdk::getCurrentHub();
        $hub = new Hub($client);
        SentrySdk::init();
        SentrySdk::setRuntimeContextStorage($storage);
        SentrySdk::setCurrentHub($hub);
        $middleware = new FlushEventsMiddleware(
            new RuntimeContextBoundary($hub, $storage),
        );

        try {
            Coroutine::create(function () use ($handled, $middleware, $storage): void {
                $response = $middleware->handle(
                    Request::create('/'),
                    function () use ($storage): Response {
                        $this->assertNotNull($storage->get());

                        return new Response('OK');
                    },
                );

                $handled->push($response->getContent());
            });

            $this->assertSame('OK', $handled->pop(1.0));
            $this->assertTrue($flushed->pop(1.0));
            $this->assertFalse(method_exists(FlushEventsMiddleware::class, 'terminate'));
        } finally {
            SentrySdk::setCurrentHub($previousHub);
        }
    }
}
