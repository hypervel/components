<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Closure;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Http\Request;
use Hypervel\HttpServer\Events\RequestCanceled;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\ResponseSent;
use Hypervel\Reverb\Servers\Hypervel\HttpServer;
use Hypervel\Reverb\Servers\Hypervel\ReverbRouter;
use Hypervel\Tests\Reverb\ReverbTestCase;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HttpServerTest extends ReverbTestCase
{
    /**
     * Clean up the worker-start coordinator.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        CoordinatorManager::clear(Constants::WORKER_START);
    }

    public function testRejectsRequestsOverMaxRequestSizeUsingContentLength(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $this->app->make('config')->set('reverb.servers.reverb.max_request_size', 10);

        $server = $this->makeHttpServer();
        $swooleRequest = $this->makeSwooleRequest(
            uri: '/apps/123456/events',
            method: 'post',
            headers: ['content-length' => '11'],
            rawContent: str_repeat('a', 11)
        );
        $swooleResponse = $this->makeSwooleResponse(413, 'Payload Too Large');

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    public function testAllowsRequestsWithinMaxRequestSize(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $this->app->make('config')->set('reverb.servers.reverb.max_request_size', 10);

        $server = $this->makeHttpServer();
        $swooleRequest = $this->makeSwooleRequest(
            uri: '/up',
            headers: ['content-length' => '10'],
            rawContent: str_repeat('a', 10)
        );
        $swooleResponse = $this->makeSwooleResponse(200, '{"health":"OK"}');

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    public function testDispatchesHttpLifecycleForRoutedRequests(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $order = [];
        $observedEvents = [];
        $events = $this->app->make('events');

        foreach ([RequestReceived::class, RequestHandled::class, ResponseSent::class] as $eventClass) {
            $events->listen($eventClass, function (object $event) use (&$order, &$observedEvents): void {
                $order[] = $event::class;
                $observedEvents[$event::class] = $event;
            });
        }

        $server = $this->makeHttpServer();
        $response = $this->makeSwooleResponse(
            200,
            '{"health":"OK"}',
            function () use (&$order): void {
                $order[] = 'send';
            },
        );

        $server->onRequest($this->makeSwooleRequest('/up'), $response);

        $this->assertSame([
            RequestReceived::class,
            RequestHandled::class,
            'send',
            ResponseSent::class,
        ], $order);
        $this->assertNull($observedEvents[RequestReceived::class]->response);
        $this->assertSame(200, $observedEvents[RequestHandled::class]->response->getStatusCode());
        $this->assertSame($observedEvents[RequestHandled::class]->response, $observedEvents[ResponseSent::class]->response);
        $this->assertNull($observedEvents[ResponseSent::class]->exception);
        $this->assertSame('reverb', $observedEvents[ResponseSent::class]->server);
    }

    public function testPreflightRejectionDispatchesNoHttpLifecycleEvents(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $this->app->make('config')->set('reverb.servers.reverb.max_request_size', 10);
        $dispatchedEvents = [];
        $events = $this->app->make('events');

        foreach ([RequestReceived::class, RequestHandled::class, ResponseSent::class] as $eventClass) {
            $events->listen($eventClass, function (object $event) use (&$dispatchedEvents): void {
                $dispatchedEvents[] = $event::class;
            });
        }

        $this->makeHttpServer()->onRequest(
            $this->makeSwooleRequest(
                uri: '/apps/123456/events',
                method: 'post',
                headers: ['content-length' => '11'],
                rawContent: str_repeat('a', 11),
            ),
            $this->makeSwooleResponse(413, 'Payload Too Large'),
        );

        $this->assertSame([], $dispatchedEvents);
    }

    public function testCancellationSkipsFallbackAndCompletionEventsButPublishesTheCancellation(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $cancellation = new CanceledException;
        $router = Double::for(ReverbRouter::class)->strict();
        $router->expects('compileAndWarm');
        $router->expects('dispatch')->throws($cancellation);
        $this->app->instance(ReverbRouter::class, $router);
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->never();
        $handler->expects('render')->never();
        $this->app->instance(ExceptionHandler::class, $handler);

        $dispatchedEvents = [];
        $events = $this->app->make('events');
        foreach ([RequestReceived::class, RequestHandled::class, ResponseSent::class, RequestCanceled::class] as $eventClass) {
            $events->listen($eventClass, function (object $event) use (&$dispatchedEvents): void {
                $dispatchedEvents[$event::class] = $event;
            });
        }

        $swooleResponse = Double::for(SwooleResponse::class)->strict();

        try {
            $this->makeHttpServer()->onRequest(
                $this->makeSwooleRequest('/up'),
                $swooleResponse,
            );
            $this->fail('Expected request cancellation to propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $swooleResponse->unused();

        $this->assertSame([RequestReceived::class, RequestCanceled::class], array_keys($dispatchedEvents));
        $this->assertSame($cancellation, $dispatchedEvents[RequestCanceled::class]->cancellation);
        $this->assertNull($dispatchedEvents[RequestCanceled::class]->response);
        $this->assertNull($dispatchedEvents[RequestCanceled::class]->exception);
    }

    public function testCancellationAfterAHeldFailurePublishesItAndReportsTheFailures(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $heldFailure = new RuntimeException('Handled listener failed.');
        $listenerFailure = new RuntimeException('Canceled listener failed.');
        $cancellation = new CanceledException;
        $router = Double::for(ReverbRouter::class)->strict();
        $router->expects('compileAndWarm');
        $router->expects('dispatch')->returns(new Response('OK'));
        $this->app->instance(ReverbRouter::class, $router);
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($heldFailure))->ordered();
        $handler->expects('report')->with(Argument::same($listenerFailure))->ordered();
        $this->app->instance(ExceptionHandler::class, $handler);

        $canceled = null;
        $events = $this->app->make('events');
        $events->listen(RequestHandled::class, static fn () => throw $heldFailure);
        $events->listen(ResponseSent::class, static fn () => throw $cancellation);
        $events->listen(RequestCanceled::class, static function (RequestCanceled $event) use (&$canceled, $listenerFailure): never {
            $canceled = $event;

            throw $listenerFailure;
        });

        try {
            $this->makeHttpServer()->onRequest(
                $this->makeSwooleRequest('/up'),
                $this->makeSwooleResponse(200, 'OK'),
            );
            $this->fail('Expected request cancellation to propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame($heldFailure, $canceled->exception);
        $this->assertNotNull($canceled->response);
    }

    public function testRejectsRequestsOverMaxRequestSizeWithoutContentLength(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $this->app->make('config')->set('reverb.servers.reverb.max_request_size', 10);

        $server = $this->makeHttpServer();
        $swooleRequest = $this->makeSwooleRequest(
            uri: '/apps/123456/events',
            method: 'post',
            rawContent: str_repeat('a', 11)
        );
        $swooleResponse = $this->makeSwooleResponse(413, 'Payload Too Large');

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    #[DataProvider('invalidContentLengthProvider')]
    public function testFallsBackToBodySizeForInvalidContentLength(string $contentLength): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $this->app->make('config')->set('reverb.servers.reverb.max_request_size', 10);

        $server = $this->makeHttpServer();
        $swooleRequest = $this->makeSwooleRequest(
            uri: '/apps/123456/events',
            method: 'post',
            headers: ['content-length' => $contentLength],
            rawContent: str_repeat('a', 11)
        );
        $swooleResponse = $this->makeSwooleResponse(413, 'Payload Too Large');

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    /**
     * Provide invalid content-length headers.
     */
    public static function invalidContentLengthProvider(): array
    {
        return [
            'non-numeric' => ['invalid'],
            'negative' => ['-1'],
            'decimal' => ['10.5'],
            'scientific' => ['1e1'],
        ];
    }

    public function testEmitsFallbackResponseWhenRequestIsUnavailable(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $original = new RuntimeException('Request body failed');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($original));
        $handler->expects('render')->never();
        $this->app->instance(ExceptionHandler::class, $handler);

        $this->makeHttpServer()->onRequest(
            $this->makeSwooleRequest('/up', rawContent: $original),
            $this->makeSwooleResponse(500, 'Internal Server Error'),
        );
    }

    public function testRetainsRequestFailureWhenExceptionReportingFails(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $original = new RuntimeException('Request failed');
        $reportingFailure = new RuntimeException('Reporting failed');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($original))->throws($reportingFailure);
        $handler->expects('render')->never();
        $this->app->instance(ExceptionHandler::class, $handler);

        try {
            $this->makeFailingHttpServer($original)->onRequest(
                $this->makeSwooleRequest('/up'),
                Double::for(SwooleResponse::class)->strict(),
            );
            $this->fail('Expected exception reporting to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($reportingFailure, $exception);
            $this->assertSame($original, $exception->getPrevious());
        }
    }

    public function testReportsRendersAndEmitsRequestFailure(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $original = new RuntimeException('Request failed');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($original));
        $handler->expects('render')
            ->with(Argument::type(Request::class), Argument::same($original))
            ->returns(new Response('Internal Server Error', 500));
        $this->app->instance(ExceptionHandler::class, $handler);

        $observedEvents = [];
        $events = $this->app->make('events');
        foreach ([RequestReceived::class, RequestHandled::class, ResponseSent::class] as $eventClass) {
            $events->listen($eventClass, function (object $event) use (&$observedEvents): void {
                $observedEvents[$event::class] = $event;
            });
        }

        $this->makeFailingHttpServer($original)->onRequest(
            $this->makeSwooleRequest('/up'),
            $this->makeSwooleResponse(500, 'Internal Server Error'),
        );

        $this->assertArrayHasKey(RequestReceived::class, $observedEvents);
        $this->assertSame($original, $observedEvents[RequestHandled::class]->exception);
        $this->assertSame($original, $observedEvents[ResponseSent::class]->exception);
    }

    public function testPreservesCancellationWithoutReportingRenderingOrEmittingResponse(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $cancellation = new CanceledException;
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->never();
        $handler->expects('render')->never();
        $this->app->instance(ExceptionHandler::class, $handler);

        $swooleResponse = Double::for(SwooleResponse::class)->strict();

        try {
            $this->makeFailingHttpServer($cancellation)->onRequest(
                $this->makeSwooleRequest('/up'),
                $swooleResponse,
            );
            $this->fail('Expected cancellation to propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $swooleResponse->unused();
    }

    public function testRetainsRequestFailureWhenResponseEmissionFails(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $original = new RuntimeException('Request failed');
        $emissionFailure = new RuntimeException('Response emission failed');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($original));
        $handler->expects('render')
            ->with(Argument::type(Request::class), Argument::same($original))
            ->returns(new Response('Internal Server Error', 500));
        $this->app->instance(ExceptionHandler::class, $handler);

        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->expects('status')->with(500)->throws($emissionFailure);

        try {
            $this->makeFailingHttpServer($original)->onRequest(
                $this->makeSwooleRequest('/up'),
                $swooleResponse,
            );
            $this->fail('Expected response emission to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($emissionFailure, $exception);
            $this->assertSame($original, $exception->getPrevious());
        }
    }

    public function testResponseSentObservesRoutedResponseEmissionFailure(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $emissionFailure = new RuntimeException('Response emission failed');
        $sentEvent = null;
        $this->app->make('events')->listen(
            ResponseSent::class,
            function (ResponseSent $event) use (&$sentEvent): void {
                $sentEvent = $event;
            },
        );
        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->expects('status')->with(200)->throws($emissionFailure);

        try {
            $this->makeHttpServer()->onRequest(
                $this->makeSwooleRequest('/up'),
                $swooleResponse,
            );
            $this->fail('Expected response emission to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($emissionFailure, $exception);
        }

        $this->assertInstanceOf(ResponseSent::class, $sentEvent);
        $this->assertSame($emissionFailure, $sentEvent->exception);
    }

    /**
     * Create a bootstrapped Reverb HTTP server.
     */
    protected function makeHttpServer(): HttpServer
    {
        $server = new HttpServer($this->app);
        $server->bootstrapForServer('reverb');

        return $server;
    }

    /**
     * Create a Reverb HTTP server whose router throws.
     */
    protected function makeFailingHttpServer(Throwable $exception): HttpServer
    {
        $router = Double::for(ReverbRouter::class)->strict();
        $router->expects('compileAndWarm');
        $router->expects('dispatch')->throws($exception);
        $this->app->instance(ReverbRouter::class, $router);

        return $this->makeHttpServer();
    }

    /**
     * Create a native request with the given body and headers.
     */
    protected function makeSwooleRequest(
        string $uri,
        string $method = 'get',
        array $headers = [],
        string|false|Throwable $rawContent = false,
    ): SwooleRequest {
        $swooleRequest = Double::for(SwooleRequest::class)->strict();
        $swooleRequest->server = [
            'request_method' => $method,
            'request_uri' => $uri,
        ];
        $swooleRequest->header = array_replace(['host' => 'example.com'], $headers);
        $swooleRequest->get = [];
        $swooleRequest->post = [];
        $swooleRequest->cookie = [];
        $swooleRequest->files = [];
        if ($rawContent instanceof Throwable) {
            $swooleRequest->allows('rawContent')->throws($rawContent);
        } else {
            $swooleRequest->allows('rawContent')->returns($rawContent);
        }

        return $swooleRequest;
    }

    /**
     * Expect the response status and body to be sent.
     */
    protected function makeSwooleResponse(int $status, string $body, ?Closure $onEnd = null): SwooleResponse
    {
        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->expects('status')->with($status)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $expectation = $swooleResponse->expects('end')->with($body);

        if ($onEnd === null) {
            $expectation->returns(true);
        } else {
            $expectation->resolves(function () use ($onEnd): bool {
                $onEnd();

                return true;
            });
        }

        return $swooleResponse;
    }
}
