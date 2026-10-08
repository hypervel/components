<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use GuzzleHttp\Cookie\CookieJar;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Cache\Traits\HasCaching;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

class RequestCloneTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testCloningARequestAfterQueryIsInitializedGivesIndependentQueryBags(): void
    {
        $original = (new UserRequest)->withQueryParameters(['key' => 'value']);

        $a = clone $original;
        $b = clone $original;

        $a->withQueryParameters(['page' => 1]);
        $b->withQueryParameters(['page' => 2]);

        $this->assertSame(1, $a->queryParameters()['page']);
        $this->assertSame(2, $b->queryParameters()['page']);
        $this->assertArrayNotHasKey('page', $original->queryParameters());
        $this->assertSame('value', $original->queryParameters()['key']);
    }

    public function testCloningARequestAfterHeadersConfigAndDelayAreInitializedGivesIndependentStores(): void
    {
        $original = (new UserRequest)
            ->withHeader('X-Test', 'one')
            ->withOptions(['timeout' => 10])
            ->delay(5);

        $clone = clone $original;

        // Upstream's header store replaces a value on add; Hypervel's withHeader appends, so the clone replaces it.
        $clone
            ->replaceHeaders(['X-Test' => 'two'])
            ->withOptions(['timeout' => 20])
            ->delay(15);

        $this->assertSame('one', $original->headers()['X-Test']);
        $this->assertSame('two', $clone->headers()['X-Test']);
        $this->assertSame(10, $original->options()['timeout']);
        $this->assertSame(20, $clone->options()['timeout']);
        $this->assertSame(5, $original->delayMilliseconds());
        $this->assertSame(15, $clone->delayMilliseconds());
    }

    public function testCloningARequestAfterMiddlewareIsInitializedGivesIndependentPipelines(): void
    {
        $original = new UserRequest;
        $original->middleware()->onResponse(static fn (Response $response): Response => $response);

        $clone = clone $original;

        $this->assertNotSame($original->middleware(), $clone->middleware());
    }

    public function testConcurrentPoolSendsWithClonedRequestsDoNotShareQueryMutation(): void
    {
        $sequence = [];

        for ($i = 0; $i < 10; ++$i) {
            $sequence[] = MockResponse::make(['ok' => true]);
        }

        // Connectors are read-only, so the shared mock client is attached to the request that is cloned.
        $base = (new UserRequest)
            ->withMockClient(new MockClient($sequence))
            ->withQueryParameters(['key' => 'value']);

        $requests = [];

        for ($i = 1; $i <= 10; ++$i) {
            $requests[] = (clone $base)->withQueryParameters(['page' => $i]);
        }

        $pagesSeen = [];

        $pool = (new TestConnector)->pool($requests, 5);
        $pool->withResponseHandler(function (Response $response) use (&$pagesSeen): void {
            $pagesSeen[] = (int) $response->request()->queryParameters()['page'];
        });

        $pool->send();

        $this->assertCount(10, $pagesSeen);
        $this->assertCount(10, array_unique($pagesSeen));
    }

    public function testCloneOwnsIndependentInitializedRequestState(): void
    {
        $this->assertNull((new CloneRequestStub)->url());
        $request = (new CloneRequestStub)
            ->withUrl('https://api.example.test/users/1')
            ->withHeader('X-Original', 'yes')
            ->withQueryParameters(['page' => 1])
            ->withUrlParameters(['version' => 'v1'])
            ->withOptions(['verify' => true])
            ->delay(10)
            ->withCookies(['original' => 'yes'], '.example.test')
            ->retry([10])
            ->withData(['original' => true])
            ->disableCaching();
        $request->middleware()->onRequest(static fn (PendingRequest $pendingRequest): PendingRequest => $pendingRequest, 'original');

        $clone = clone $request;
        $clone
            ->withUrl('https://api.example.test/users/2')
            ->withHeader('X-Clone', 'yes')
            ->withQueryParameters(['page' => 2])
            ->withUrlParameters(['version' => 'v2'])
            ->withOptions(['verify' => false])
            ->delay(20)
            ->withCookies(['clone' => 'yes'], 'api.example.test')
            ->retry(3, 20)
            ->withData(['clone' => true])
            ->enableCaching()
            ->invalidateCache();
        $clone->middleware()->onRequest(static fn (PendingRequest $pendingRequest): PendingRequest => $pendingRequest, 'clone');

        $this->assertSame('https://api.example.test/users/1', $request->url());
        $this->assertSame('https://api.example.test/users/2', $clone->url());
        $this->assertSame('yes', $request->headers()['X-Original']);
        $this->assertSame('application/json', $request->headers()['Content-Type']);
        $this->assertSame(['page' => 1], $request->queryParameters());
        $this->assertSame(['version' => 'v1'], $request->urlParameters());
        $this->assertTrue($request->options()['verify']);
        $this->assertSame(10, $request->delayMilliseconds());
        $this->assertCount(1, $request->middleware()->requestPipeline()->pipes());
        $this->assertSame(['original' => true], $request->body());
        $this->assertSame(CookieJar::fromArray(['original' => 'yes'], '.example.test')->toArray(), $request->cookies());
        $this->assertSame([10], $request->retryPolicy()->times);
        $this->assertFalse($request->cachingEnabled());
        $this->assertFalse($request->shouldInvalidateCache());

        $this->assertSame(['original' => true, 'clone' => true], $clone->body());
        $this->assertCount(2, $clone->cookies());
        $this->assertSame(3, $clone->retryPolicy()->times);
        $this->assertTrue($clone->cachingEnabled());
        $this->assertTrue($clone->shouldInvalidateCache());
    }
}

class CloneRequestStub extends Request
{
    use HasCaching;

    protected Method $method = Method::GET;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return 'users';
    }
}
