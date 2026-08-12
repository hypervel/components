<?php

declare(strict_types=1);

namespace Hypervel\Tests\ApiClient;

use Hypervel\ApiClient\ApiClient;
use Hypervel\ApiClient\ApiResource;
use Hypervel\ApiClient\PendingRequest;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\Response;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;

class ApiClientTest extends TestCase
{
    public function testSendRequest(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'https://example.test/test-endpoint' => Http::response('{"success": true}'),
        ]);

        $client = new ApiClient;
        $response = $client->post('https://example.test/test-endpoint', ['foo' => 'bar']);

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
            'https://example.test/test-endpoint' => Http::response('{"success": true}'),
        ]);

        $client = new ApiClient;
        $response = $client
            ->withToken('test-token')
            ->asForm()
            ->post('https://example.test/test-endpoint', ['foo' => 'bar']);

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

    public function testConcreteClientConfiguresFreshPendingRequests(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/issues' => Http::response(['id' => 1]),
        ]);

        $client = new ApiClientTestClient('secret');

        $first = $client->createPendingRequest();
        $second = $client->createPendingRequest();

        $this->assertNotSame($first, $second);
        $this->assertSame('tenant-1', $first->context('tenant'));
        $this->assertSame('fallback', $first->context('missing', 'fallback'));

        $resource = $first->get('/issues');

        $this->assertInstanceOf(ApiClientTestResource::class, $resource);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/issues'
            && $request->hasHeader('Authorization', 'Bearer secret'));
    }

    public function testDynamicCallsDoNotMutateTheClientPrototype(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/*' => Http::response([]),
        ]);

        $client = new ApiClient;

        $client->withHeader('X-Operation', 'first')->get('https://example.test/first');
        $client->get('https://example.test/second');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/first'
            && $request->hasHeader('X-Operation', 'first'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/second'
            && ! $request->hasHeader('X-Operation'));
    }
}

/** @extends ApiClient<ApiClientTestResource> */
class ApiClientTestClient extends ApiClient
{
    protected string $resource = ApiClientTestResource::class;

    public function __construct(protected string $token)
    {
    }

    protected function configurePendingRequest(PendingRequest $request): void
    {
        $request
            ->baseUrl('https://example.test')
            ->withToken($this->token)
            ->withContext('tenant', 'tenant-1');
    }
}

class ApiClientTestResource extends ApiResource
{
}
