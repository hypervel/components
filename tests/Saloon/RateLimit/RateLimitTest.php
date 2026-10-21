<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit;

use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Contracts\Decision;
use Hypervel\RateLimiter\Cooldown;
use Hypervel\RateLimiter\KeyResolver;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\RateLimiter\WorkerArrayStore;
use Hypervel\Saloon\Cache\Contracts\Cacheable;
use Hypervel\Saloon\Cache\Traits\HasCaching;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\RateLimit\Exceptions\RateLimitReachedException;
use Hypervel\Saloon\RateLimit\Traits\HasRateLimits;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Sleep;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;

// Feature/HasRateLimitsTest covers consuming connector and request policies, denials before transport, cooldowns
// recorded before response middleware and waiting, and Unit/HelperTest covers Retry-After parsing.
class RateLimitTest extends TestCase
{
    public function testFakesAndCacheHitsDoNotConsumeAdmissionCapacity(): void
    {
        [$manager, $limiter, $http] = $this->manager();
        $http->fake(['*' => Factory::response(['network' => true])]);
        $connector = new PlainRateLimitConnectorStub($manager);
        $policy = Limit::perMinute(2)->by('cache');
        $request = new CachedRateLimitedRequestStub($policy);

        $manager->fake((new MockClient([MockResponse::make(['fake' => true])]))->withoutCache());
        $connector->send($request);
        $manager->clearFake();

        $this->assertSame(2, $limiter->inspect($policy, 'saloon:' . $request::class)->remaining());

        $network = $connector->send($request);
        $cached = $connector->send($request);

        $this->assertFalse($network->isCached());
        $this->assertTrue($cached->isCached());
        $this->assertSame(1, $limiter->inspect($policy, 'saloon:' . $request::class)->remaining());
        $http->assertSentCount(1);
    }

    public function testPoliciesCanUseOperationStateAndSelectAStore(): void
    {
        [$manager, $limiter, $http, $rateLimiter] = $this->manager();
        $http->fake(['*' => Factory::response(['ok' => true])]);
        $rateLimiter->shouldReceive('store')->twice()->with('secondary')->andReturn($limiter);
        $connector = new PlainRateLimitConnectorStub($manager);
        $tenantA = (new OperationRateLimitRequestStub)->withHeader('X-Tenant', 'tenant-a');
        $tenantB = (new OperationRateLimitRequestStub)->withHeader('X-Tenant', 'tenant-b');

        $connector->send($tenantA);
        $connector->send($tenantB);

        $this->assertSame(1, $limiter->inspect(
            Limit::perMinute(2)->by('tenant-a'),
            'saloon:' . $tenantA::class,
        )->remaining());
        $this->assertSame(1, $limiter->inspect(
            Limit::perMinute(2)->by('tenant-b'),
            'saloon:' . $tenantB::class,
        )->remaining());
    }

    public function testDeniedRequestGroupDoesNotChargeItsEarlierPolicyButKeepsTheSeparateConnectorCharge(): void
    {
        [$manager, $limiter, $http] = $this->manager();
        $http->fake(['*' => Factory::response(['unexpected' => true])]);
        $connector = new RateLimitedConnectorStub($manager);
        $request = new class extends MultipleRateLimitRequestStub {
            /**
             * Resolve both policies in one request group.
             */
            protected function resolveRateLimits(PendingRequest $pendingRequest): array
            {
                return ['first' => $this->firstPolicy(), 'second' => $this->secondPolicy()];
            }
        };
        $limiterName = 'saloon:' . $request::class;

        $limiter->consume($request->secondPolicy(), $limiterName);

        try {
            $connector->send($request);
            $this->fail('A denied second policy reached transport.');
        } catch (RateLimitReachedException $exception) {
            $this->assertSame('second', $exception->policy()->key);
        }

        $this->assertSame(10, $limiter->inspect($request->firstPolicy(), $limiterName)->remaining());
        $this->assertSame(0, $limiter->inspect($request->secondPolicy(), $limiterName)->remaining());
        $this->assertSame(1, $limiter->inspect($connector->policy(), 'saloon:' . $connector::class)->remaining());
        $http->assertNothingSent();
    }

    public function testWaitingForAGroupRechecksCooldownBeforeChargingTheGroup(): void
    {
        CarbonImmutable::setTestNow('2026-08-13 12:00:00 UTC');
        Sleep::fake(syncWithCarbon: true);
        [$manager, $limiter, $http] = $this->manager();
        $http->fake(['*' => Factory::response(['ok' => true])]);
        $connector = new PlainRateLimitConnectorStub($manager);
        $request = new WaitingMultipleRateLimitRequestStub;
        $limiterName = 'saloon:' . $request::class;
        $limiter->consume($request->secondPolicy(), $limiterName);
        $published = false;

        Sleep::whenFakingSleep(function () use ($limiter, $request, $limiterName, &$published): void {
            $this->assertSame(10, $limiter->inspect($request->firstPolicy(), $limiterName)->remaining());

            if (! $published) {
                $limiter->block(Cooldown::for(null), 5, $limiterName);
                $published = true;
            }
        });

        $this->assertTrue($connector->send($request)->successful());

        Sleep::assertSequence([Sleep::for(60)->seconds(), Sleep::for(5)->seconds()]);
        $this->assertSame(9, $limiter->inspect($request->firstPolicy(), $limiterName)->remaining());
        $this->assertSame(0, $limiter->inspect($request->secondPolicy(), $limiterName)->remaining());
        $http->assertSentCount(1);
    }

    public function testServerOnlyPolicyCallbacksAreRejected(): void
    {
        [$manager, , $http] = $this->manager();
        $http->fake(['*' => Factory::response(['unexpected' => true])]);
        $connector = new PlainRateLimitConnectorStub($manager);

        $this->expectException(InvalidArgumentException::class);

        $connector->send(new CallbackRateLimitRequestStub);
    }

    /**
     * Create a manager and isolated worker-array limiter.
     *
     * @return array{SaloonManager, Limiter, Factory, RateLimiter}
     */
    protected function manager(): array
    {
        $http = new Factory;
        $http->registerConnection('saloon');
        $cacheRepository = new Repository(new ArrayStore);
        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->with(null)->andReturn($cacheRepository)->byDefault();
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')->with('saloon.connection.name')->andReturn('saloon');
        $config->shouldReceive('get')->with('saloon.cache.store')->andReturn(null)->byDefault();
        $config->shouldReceive('get')->with('saloon.rate_limiter.store')->andReturn(null)->byDefault();
        $limiter = new Limiter(
            new WorkerArrayStore,
            new KeyResolver('saloon-tests', static fn (): ?string => null),
        );
        $rateLimiter = m::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('store')->with(null)->andReturn($limiter)->byDefault();
        $manager = new SaloonManager(
            new Sender($http, $config),
            $cache,
            $rateLimiter,
            $config,
            new Dispatcher,
        );

        return [$manager, $limiter, $http, $rateLimiter];
    }
}

class PlainRateLimitConnectorStub extends Connector
{
    /**
     * Create a connector that sends through the given manager.
     */
    public function __construct(protected SaloonManager $manager)
    {
    }

    /**
     * Resolve the integration base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    /**
     * Send a request through the given manager.
     */
    public function send(Request $request, ?MockClient $mockClient = null): Response
    {
        return $this->manager->send($this, $request, $mockClient);
    }
}

class RateLimitedConnectorStub extends PlainRateLimitConnectorStub
{
    use HasRateLimits;

    /**
     * Create a connector with the given attempt limit.
     */
    public function __construct(
        SaloonManager $manager,
        protected int $maximumAttempts = 2,
    ) {
        parent::__construct($manager);
    }

    /**
     * Get the connector policy.
     */
    public function policy(): AdmissionPolicy
    {
        return Limit::perMinute($this->maximumAttempts)->by('connector');
    }

    /**
     * Resolve the connector rate limits.
     */
    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [$this->policy()];
    }
}

class PlainRateLimitRequestStub extends Request
{
    protected Method $method = Method::GET;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/resource';
    }
}

class CachedRateLimitedRequestStub extends PlainRateLimitRequestStub implements Cacheable
{
    use HasCaching;
    use HasRateLimits;

    /**
     * Create a cached request with the given policy.
     */
    public function __construct(protected AdmissionPolicy $policy)
    {
    }

    /**
     * Get the cache duration.
     */
    public function cacheFor(): DateInterval|DateTimeInterface|int
    {
        return 60;
    }

    /**
     * Resolve the request rate limits.
     */
    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [$this->policy];
    }
}

class CallbackRateLimitRequestStub extends PlainRateLimitRequestStub
{
    use HasRateLimits;

    /**
     * Resolve a policy with an after callback.
     */
    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [Limit::perMinute(1)->after(static fn (): bool => true)];
    }
}

class OperationRateLimitRequestStub extends PlainRateLimitRequestStub
{
    use HasRateLimits;

    /**
     * Resolve a policy keyed by the operation's tenant header.
     */
    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [Limit::perMinute(2)->by((string) $pendingRequest->headers()['X-Tenant'])];
    }

    /**
     * Resolve the rate-limiter store name.
     */
    protected function resolveRateLimitStore(): string
    {
        return 'secondary';
    }
}

class MultipleRateLimitRequestStub extends PlainRateLimitRequestStub
{
    use HasRateLimits;

    /**
     * Get the first policy.
     */
    public function firstPolicy(): AdmissionPolicy
    {
        return Limit::perMinute(10)->by('first');
    }

    /**
     * Get the second policy.
     */
    public function secondPolicy(): AdmissionPolicy
    {
        return Limit::perMinute(1)->by('second');
    }

    /**
     * Resolve both policies.
     */
    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [$this->firstPolicy(), $this->secondPolicy()];
    }
}

class WaitingMultipleRateLimitRequestStub extends MultipleRateLimitRequestStub
{
    /**
     * Wait for rate limits instead of throwing.
     */
    protected function waitForRateLimits(AdmissionPolicy|Cooldown $policy, Decision $result): bool
    {
        return true;
    }
}
