<?php

declare(strict_types=1);

namespace Hypervel\Tests\ApiClient;

use Hypervel\ApiClient\ApiClient;
use Hypervel\ApiClient\ApiResource;
use Hypervel\HttpClient\Request;
use Hypervel\HttpClient\Response;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @covers \Hypervel\ApiClient\ApiClient
 */
class ApiClientTest extends TestCase
{
    public function testSendRequest(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'test-endpoint' => Http::response('{"success": true}'),
        ]);

        $client = new ApiClient();
        $response = $client->post('test-endpoint', ['foo' => 'bar']);

        $this->assertInstanceOf(ApiResource::class, $response);
        $this->assertInstanceOf(Response::class, $response->getResponse());
        $this->assertInstanceOf(Request::class, $response->getRequest());
        $this->assertSame(['foo' => 'bar'], $response->getRequest()->data());
        $this->assertSame(['success' => true], $response->json());
        $this->assertSame('{"success": true}', $response->body());
        $this->assertTrue($response['success']);

        Http::assertSent(function (Request $request) {
            return $request['foo'] === 'bar';
        });
    }

    public function testSendRequestWithDecoration(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'test-endpoint' => Http::response('{"success": true}'),
        ]);

        $client = new ApiClient();
        $response = $client
            ->withToken('test-token')
            ->asForm()
            ->post('test-endpoint', ['foo' => 'bar']);

        $this->assertInstanceOf(ApiResource::class, $response);
        $this->assertInstanceOf(Response::class, $response->getResponse());
        $this->assertInstanceOf(Request::class, $response->getRequest());
        $this->assertSame(['foo' => 'bar'], $response->getRequest()->data());
        $this->assertSame(['success' => true], $response->json());
        $this->assertSame('{"success": true}', $response->body());
        $this->assertTrue($response['success']);

        Http::assertSent(function (Request $request) {
            return $request['foo'] === 'bar'
                && $request->header('Authorization')[0] === 'Bearer test-token'
                && $request->header('Content-Type')[0] === 'application/x-www-form-urlencoded';
        });
    }
}
