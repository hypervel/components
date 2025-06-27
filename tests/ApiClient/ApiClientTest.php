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
}
