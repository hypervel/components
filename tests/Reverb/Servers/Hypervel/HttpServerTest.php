<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Reverb\Servers\Hypervel\HttpServer;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

class HttpServerTest extends ReverbTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        CoordinatorManager::clear(Constants::WORKER_START);
    }

    public function testRejectsRequestsOverMaxRequestSizeUsingContentLength()
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

    public function testAllowsRequestsWithinMaxRequestSize()
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

    public function testRejectsRequestsOverMaxRequestSizeWithoutContentLength()
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
    public function testFallsBackToBodySizeForInvalidContentLength(string $contentLength)
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

    protected function makeHttpServer(): HttpServer
    {
        $server = new HttpServer($this->app);
        $server->bootstrapForServer('reverb');

        return $server;
    }

    protected function makeSwooleRequest(
        string $uri,
        string $method = 'get',
        array $headers = [],
        string|false $rawContent = false,
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
        $swooleRequest->shouldReceive('rawContent')->andReturn($rawContent);

        return $swooleRequest;
    }

    protected function makeSwooleResponse(int $status, string $body): SwooleResponse
    {
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with($status);
        $swooleResponse->shouldReceive('header')->withAnyArgs();
        $swooleResponse->shouldReceive('end')->once()->with($body);

        return $swooleResponse;
    }
}
