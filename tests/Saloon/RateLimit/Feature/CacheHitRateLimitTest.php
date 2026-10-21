<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\RateLimiter\Limit;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Requests\CachedUserRequest;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Requests\UserRequest;

// Upstream simulates the cache plugin with request middleware returning a fake response. Hypervel reads the cache
// before rate limiting, so these cases send a cacheable request instead. Saloon fakes consume no capacity, so real
// requests are faked with Http::fake(), and the limits are inspected with Saloon::inspectRateLimit().
class CacheHitRateLimitTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Define the environment setup.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('saloon.rate_limiter.store', 'worker-array');
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['name' => 'Sam'])]);
    }

    public function testCacheHitsDoNotCountAgainstTheRateLimit(): void
    {
        $limit = Limit::perMinute(3);
        $connector = new TestConnector([$limit]);
        $this->warmCache();

        // Send 10 requests, all served from the cache. The limit of 3 should never be reached.
        for ($i = 0; $i < 10; ++$i) {
            $connector->send(new CachedUserRequest);
        }

        $this->assertGreaterThan(0, Saloon::inspectRateLimit($connector, $limit)->remaining());
    }

    public function testCacheHitsDoNotIncrementTheHitCounterInTheStore(): void
    {
        $limit = Limit::perMinute(3);
        $connector = new TestConnector([$limit]);
        $this->warmCache();

        $connector->send(new CachedUserRequest);
        $connector->send(new CachedUserRequest);
        $connector->send(new CachedUserRequest);

        // No real hits recorded, so the full capacity remains
        $this->assertSame(3, Saloon::inspectRateLimit($connector, $limit)->remaining());
    }

    public function testRealApiResponsesStillCountAgainstTheRateLimit(): void
    {
        $limit = Limit::perMinute(3);
        $connector = new TestConnector([$limit]);

        $connector->send(new UserRequest);
        $connector->send(new UserRequest);
        $connector->send(new UserRequest);

        $this->assertSame(0, Saloon::inspectRateLimit($connector, $limit)->remaining());
    }

    public function testCacheHitsDoNotThrowWhenTheRateLimitIsAlreadyExhaustedByRealRequests(): void
    {
        $limit = Limit::perMinute(3);
        $connector = new TestConnector([$limit]);
        $this->warmCache();

        // Exhaust the limit with 3 real requests
        $connector->send(new UserRequest);
        $connector->send(new UserRequest);
        $connector->send(new UserRequest);

        $this->assertSame(0, Saloon::inspectRateLimit($connector, $limit)->remaining());

        $this->assertTrue($connector->send(new CachedUserRequest)->isCached());
    }

    public function testOnlyRealRequestsCountTowardTheLimitWhenMixedWithCacheHits(): void
    {
        $limit = Limit::perMinute(3);
        $connector = new TestConnector([$limit]);
        $this->warmCache();

        $connector->send(new UserRequest);

        // 5 cache hits should not add to the count
        for ($i = 0; $i < 5; ++$i) {
            $connector->send(new CachedUserRequest);
        }

        // Only 1 real hit, so the limit of 3 is not reached
        $this->assertSame(2, Saloon::inspectRateLimit($connector, $limit)->remaining());
    }

    /**
     * Fill the response cache through a connector without rate limits.
     */
    private function warmCache(): void
    {
        $this->assertFalse((new TestConnector([]))->send(new CachedUserRequest)->isCached());
    }
}
