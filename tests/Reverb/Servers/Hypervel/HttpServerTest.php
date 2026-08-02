<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Http\Request;
use Hypervel\Reverb\Servers\Hypervel\HttpServer;
use Hypervel\Reverb\Servers\Hypervel\ReverbRouter;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HttpServerTest extends ReverbTestCase
{
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
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($original);
        $handler->shouldNotReceive('render');
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
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($original)->andThrow($reportingFailure);
        $handler->shouldNotReceive('render');
        $this->app->instance(ExceptionHandler::class, $handler);

        try {
            $this->makeFailingHttpServer($original)->onRequest(
                $this->makeSwooleRequest('/up'),
                m::mock(SwooleResponse::class),
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
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($original);
        $handler->shouldReceive('render')
            ->once()
            ->with(m::type(Request::class), $original)
            ->andReturn(new Response('Internal Server Error', 500));
        $this->app->instance(ExceptionHandler::class, $handler);

        $this->makeFailingHttpServer($original)->onRequest(
            $this->makeSwooleRequest('/up'),
            $this->makeSwooleResponse(500, 'Internal Server Error'),
        );
    }

    public function testRetainsRequestFailureWhenResponseEmissionFails(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $original = new RuntimeException('Request failed');
        $emissionFailure = new RuntimeException('Response emission failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($original);
        $handler->shouldReceive('render')
            ->once()
            ->with(m::type(Request::class), $original)
            ->andReturn(new Response('Internal Server Error', 500));
        $this->app->instance(ExceptionHandler::class, $handler);

        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(500)->andThrow($emissionFailure);

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

    protected function makeHttpServer(): HttpServer
    {
        $server = new HttpServer($this->app);
        $server->bootstrapForServer('reverb');

        return $server;
    }

    protected function makeFailingHttpServer(RuntimeException $exception): HttpServer
    {
        $router = m::mock(ReverbRouter::class);
        $router->shouldReceive('compileAndWarm')->once();
        $router->shouldReceive('dispatch')->once()->andThrow($exception);
        $this->app->instance(ReverbRouter::class, $router);

        return $this->makeHttpServer();
    }

    protected function makeSwooleRequest(
        string $uri,
        string $method = 'get',
        array $headers = [],
        string|false|Throwable $rawContent = false,
    ): SwooleRequest {
        $swooleRequest = m::mock(SwooleRequest::class);
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
            $swooleRequest->shouldReceive('rawContent')->andThrow($rawContent);
        } else {
            $swooleRequest->shouldReceive('rawContent')->andReturn($rawContent);
        }

        return $swooleRequest;
    }

    protected function makeSwooleResponse(int $status, string $body): SwooleResponse
    {
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with($status)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->with($body)->andReturnTrue();

        return $swooleResponse;
    }
}
