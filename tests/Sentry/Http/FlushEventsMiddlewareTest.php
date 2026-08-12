<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Http;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Sentry\Http\FlushEventsMiddleware;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Sentry\ClientInterface;
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
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('flush')
            ->once()
            ->with(null)
            ->andReturnUsing(static function () use ($flushed): Result {
                $flushed->push(true);

                return new Result(ResultStatus::success());
            });
        $previousHub = SentrySdk::getCurrentHub();
        SentrySdk::setCurrentHub(new Hub($client));

        try {
            Coroutine::create(static function () use ($handled): void {
                $response = (new FlushEventsMiddleware)->handle(
                    Request::create('/'),
                    static fn (): Response => new Response('OK'),
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
