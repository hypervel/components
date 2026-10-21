<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Cache\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Events\SendingSaloonRequest;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Cache\Fixtures\Connectors\CachedConnector;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\CachedConnectorRequest;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\CachedUserRequest;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\CustomKeyCachedUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;

class ClearCacheTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function testClearCacheRemovesACachedResponseWithoutSendingARequest(): void
    {
        Http::fake();

        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $connector = new TestConnector;
        $request = new CachedUserRequest;

        // Send and cache the response
        $responseA = $connector->send($request, $mockClient);
        $this->assertFalse($responseA->isCached());

        // Verify it is cached
        $responseB = $connector->send(new CachedUserRequest);
        $this->assertTrue($responseB->isCached());

        // Delete the cache without sending a request
        $sending = 0;
        Event::listen(SendingSaloonRequest::class, function () use (&$sending): void {
            ++$sending;
        });

        $request = new CachedUserRequest;
        $request->clearCache($connector);

        $this->assertSame(0, $sending);
        Http::assertNothingSent();

        // Now sending should result in a cache miss
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Michael']),
        ]);

        $responseC = $connector->send(new CachedUserRequest, $mockClient);
        $this->assertFalse($responseC->isCached());
        $this->assertSame(['name' => 'Michael'], $responseC->json());
    }

    public function testClearCacheOnAnUncachedRequestDoesNotThrow(): void
    {
        $connector = new TestConnector;
        $request = new CachedUserRequest;

        $request->clearCache($connector);

        $this->assertCount(0, Cache::store()->getStore()->all());
    }

    public function testClearCacheUsesACustomCacheKeyOverride(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $connector = new TestConnector;

        // Send and cache with the custom key
        $connector->send(new CustomKeyCachedUserRequest, $mockClient);

        $this->assertCount(1, Cache::store()->getStore()->all());

        // Delete using the custom key, which ignores the request's query string
        $request = (new CustomKeyCachedUserRequest)->withQueryParameters(['page' => 2]);
        $request->clearCache($connector);

        $this->assertCount(0, Cache::store()->getStore()->all());
    }

    public function testAfterClearCacheTheNextSendFetchesFreshAndRepopulatesCache(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $connector = new TestConnector;

        // Send and cache
        $connector->send(new CachedUserRequest, $mockClient);

        // Confirm cached
        $responseB = $connector->send(new CachedUserRequest);
        $this->assertTrue($responseB->isCached());
        $this->assertSame(['name' => 'Sam'], $responseB->json());

        // Delete cache
        $request = new CachedUserRequest;
        $request->clearCache($connector);

        // Send again - should be a fresh response
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Teo']),
        ]);

        $responseC = $connector->send(new CachedUserRequest, $mockClient);
        $this->assertFalse($responseC->isCached());
        $this->assertSame(['name' => 'Teo'], $responseC->json());

        // Verify the new response is cached
        $responseD = $connector->send(new CachedUserRequest);
        $this->assertTrue($responseD->isCached());
        $this->assertSame(['name' => 'Teo'], $responseD->json());
    }

    public function testClearCacheWorksWhenCalledFromTheConnector(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $connector = new CachedConnector;

        // Send and cache
        $connector->send(new CachedConnectorRequest, $mockClient);

        // Confirm cached
        $responseB = $connector->send(new CachedConnectorRequest);
        $this->assertTrue($responseB->isCached());

        // Delete cache from the connector side
        $connector->clearCache(new CachedConnectorRequest);

        // Should be a cache miss now
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Michael']),
        ]);

        $responseC = $connector->send(new CachedConnectorRequest, $mockClient);
        $this->assertFalse($responseC->isCached());
        $this->assertSame(['name' => 'Michael'], $responseC->json());
    }

    // REMOVED: "clearCache throws when called on a connector with another connector" and "clearCache throws when
    // called on a request with another request" - the request and connector methods type their counterparts natively.

    public function testClearCacheOnlyClearsTheCurrentCacheScope(): void
    {
        $scope = 'tenant-a';
        Saloon::resolveCacheScopeUsing(static function () use (&$scope): string {
            return $scope;
        });
        $mockClient = new MockClient([
            MockResponse::make(['tenant' => 'a']),
            MockResponse::make(['tenant' => 'b']),
            MockResponse::make(['tenant' => 'a-fresh']),
        ]);
        $connector = new TestConnector;

        $connector->send(new CachedUserRequest, $mockClient);
        $scope = 'tenant-b';
        $connector->send(new CachedUserRequest, $mockClient);
        $scope = 'tenant-a';
        (new CachedUserRequest)->clearCache($connector);

        $tenantA = $connector->send(new CachedUserRequest, $mockClient);
        $scope = 'tenant-b';
        $tenantB = $connector->send(new CachedUserRequest);

        $this->assertFalse($tenantA->isCached());
        $this->assertSame(['tenant' => 'a-fresh'], $tenantA->json());
        $this->assertTrue($tenantB->isCached());
        $this->assertSame(['tenant' => 'b'], $tenantB->json());
    }

    public function testClearCacheIgnoresDisabledCaching(): void
    {
        $connector = new TestConnector;
        $connector->send(new CachedUserRequest, new MockClient([MockResponse::make(['name' => 'Sam'])]));

        (new CachedUserRequest)->disableCaching()->clearCache($connector);

        $this->assertCount(0, Cache::store()->getStore()->all());
    }
}
