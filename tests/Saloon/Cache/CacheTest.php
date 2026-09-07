<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Cache;

use DateInterval;
use DateTimeInterface;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Cache\CacheKey;
use Hypervel\Saloon\Cache\Contracts\Cacheable;
use Hypervel\Saloon\Cache\Exceptions\CachingException;
use Hypervel\Saloon\Cache\Traits\HasCaching;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Events\SendingSaloonRequest;
use Hypervel\Saloon\Events\SentSaloonRequest;
use Hypervel\Saloon\Exceptions\BodyException;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\StreamInterface;
use stdClass;
use UnitEnum;

class CacheTest extends TestCase
{
    public function testSuccessfulNetworkResponsesAreCachedWithoutReplayingTransport(): void
    {
        $http = $this->http();
        $http->fake(['*' => $http->sequence()->push(['version' => 1])->push(['version' => 2])]);
        $manager = $this->manager($http);
        $connector = new CacheConnectorStub;

        $first = $manager->send($connector, new CacheRequestStub);
        $second = $manager->send($connector, new CacheRequestStub);
        $refreshed = $manager->send($connector, (new CacheRequestStub)->invalidateCache());

        $this->assertFalse($first->isCached());
        $this->assertSame(['version' => 1], $first->json());
        $this->assertTrue($second->isCached());
        $this->assertTrue($second->isFaked());
        $this->assertFalse($second->isMocked());
        $this->assertSame(['version' => 1], $second->json());
        $this->assertFalse($refreshed->isCached());
        $this->assertSame(['version' => 2], $refreshed->json());
        $http->assertSentCount(2);
    }

    public function testSendingAndSentEventsArePairedForCacheHits(): void
    {
        $http = $this->http();
        $http->fake(['*' => Factory::response(['cached' => true])]);
        $events = new Dispatcher;
        $dispatched = [];
        $events->listen(SendingSaloonRequest::class, function () use (&$dispatched): void {
            $dispatched[] = 'sending';
        });
        $events->listen(SentSaloonRequest::class, function () use (&$dispatched): void {
            $dispatched[] = 'sent';
        });
        $manager = $this->manager($http, events: $events);

        $manager->send(new CacheConnectorStub, new CacheRequestStub);
        $cached = $manager->send(new CacheConnectorStub, new CacheRequestStub);

        $this->assertTrue($cached->isCached());
        $this->assertSame(['sending', 'sent', 'sending', 'sent'], $dispatched);
        $http->assertSentCount(1);
    }

    public function testSendingListenerMutationsAreIncludedInTheCacheIdentity(): void
    {
        $http = $this->http();
        $requests = [];
        $http->fake(function (HttpRequest $request) use (&$requests) {
            $requests[] = [$request->url(), $request->header('X-Variant'), $request->body()];

            return Factory::response(['version' => count($requests)]);
        });
        $events = new Dispatcher;
        $variant = 'a';
        $events->listen(SendingSaloonRequest::class, function (SendingSaloonRequest $event) use (&$variant): void {
            $event->pendingRequest
                ->withHeader('X-Variant', $variant)
                ->withQueryParameters(['variant' => $variant])
                ->withData(['variant' => $variant]);
        });
        $manager = $this->manager($http, events: $events);
        $connector = new CacheConnectorStub;

        $first = $manager->send($connector, new CacheRequestStub);
        $variant = 'b';
        $second = $manager->send($connector, new CacheRequestStub);
        $variant = 'a';
        $cached = $manager->send($connector, new CacheRequestStub);

        $this->assertSame(1, $first->json('version'));
        $this->assertSame(2, $second->json('version'));
        $this->assertSame(1, $cached->json('version'));
        $this->assertTrue($cached->isCached());
        $this->assertSame([
            ['https://api.example.com/users?variant=a', ['a'], '{"variant":"a"}'],
            ['https://api.example.com/users?variant=b', ['b'], '{"variant":"b"}'],
        ], $requests);
        $http->assertSentCount(2);
    }

    public function testCacheMissResolvesTransportConfigurationOnce(): void
    {
        $http = new Factory;
        $http->registerConnection('saloon');
        $http->fake(['*' => Factory::response(['ok' => true])]);
        $senderConfig = m::mock(ConfigRepository::class);
        $senderConfig->shouldReceive('string')->with('saloon.connection.name')->andReturn('saloon');
        $sender = new CountingCacheSender($http, $senderConfig);

        $this->manager($http, sender: $sender)->send(new CacheConnectorStub, new CacheRequestStub);

        $this->assertSame(1, $sender->transportResolutions);
    }

    public function testCacheScopeAppliesToReadsWritesAndInvalidation(): void
    {
        $http = $this->http();
        $http->fake(['*' => $http->sequence()
            ->push(['version' => 'tenant-a-1'])
            ->push(['version' => 'tenant-b-1'])
            ->push(['version' => 'tenant-a-2'])]);
        $manager = $this->manager($http);
        $scope = 'tenant-a';
        $manager->resolveCacheScopeUsing(static function () use (&$scope): string {
            return $scope;
        });
        $connector = new CacheConnectorStub;

        $tenantA = $manager->send($connector, new CacheRequestStub);
        $tenantACached = $manager->send($connector, new CacheRequestStub);
        $scope = 'tenant-b';
        $tenantB = $manager->send($connector, new CacheRequestStub);
        $tenantBCached = $manager->send($connector, new CacheRequestStub);
        $scope = 'tenant-a';
        $tenantARefreshed = $manager->send($connector, (new CacheRequestStub)->invalidateCache());
        $scope = 'tenant-b';
        $tenantBStillCached = $manager->send($connector, new CacheRequestStub);

        $this->assertSame('tenant-a-1', $tenantA->json('version'));
        $this->assertTrue($tenantACached->isCached());
        $this->assertSame('tenant-b-1', $tenantB->json('version'));
        $this->assertTrue($tenantBCached->isCached());
        $this->assertSame('tenant-a-2', $tenantARefreshed->json('version'));
        $this->assertFalse($tenantARefreshed->isCached());
        $this->assertSame('tenant-b-1', $tenantBStillCached->json('version'));
        $this->assertTrue($tenantBStillCached->isCached());
        $http->assertSentCount(3);
    }

    public function testCacheScopeResolverIsSkippedWhenCachingIsDisabledAndNullAllowsSharing(): void
    {
        $http = $this->http();
        $http->fake(['*' => $http->sequence()
            ->push(['version' => 1])
            ->push(['version' => 2])
            ->push(['version' => 3])]);
        $manager = $this->manager($http);
        $scope = null;
        $resolutions = 0;
        $manager->resolveCacheScopeUsing(static function () use (&$scope, &$resolutions): ?string {
            ++$resolutions;

            return $scope;
        });
        $connector = new CacheConnectorStub;

        $first = $manager->send($connector, new CacheRequestStub);
        $shared = $manager->send($connector, new CacheRequestStub);
        $manager->send($connector, (new CacheRequestStub)->disableCaching());
        $manager->send($connector, (new CacheRequestStub)->disableCaching());

        $this->assertSame(1, $first->json('version'));
        $this->assertSame(1, $shared->json('version'));
        $this->assertTrue($shared->isCached());
        $this->assertSame(2, $resolutions);
        $http->assertSentCount(3);
    }

    public function testSaloonFakesDoNotReadOrPopulateTheCache(): void
    {
        $http = $this->http();
        $http->fake(['*' => Factory::response(['network' => true])]);
        $manager = $this->manager($http);
        $connector = new CacheConnectorStub;

        $manager->fake([MockResponse::make(['fake' => true])]);
        $fake = $manager->send($connector, new CacheRequestStub);
        $manager->clearFake();
        $network = $manager->send($connector, new CacheRequestStub);
        $cached = $manager->send($connector, new CacheRequestStub);

        $this->assertSame(['fake' => true], $fake->json());
        $this->assertTrue($fake->isMocked());
        $this->assertSame(['network' => true], $network->json());
        $this->assertFalse($network->isCached());
        $this->assertSame(['network' => true], $cached->json());
        $this->assertTrue($cached->isCached());
        $http->assertSentCount(1);
    }

    public function testFailuresAndDisabledOrUnsupportedMethodsAreNotCached(): void
    {
        $http = $this->http();
        $http->fake(['*' => $http->sequence()
            ->push(['attempt' => 1], 500)
            ->push(['attempt' => 2], 500)
            ->push(['attempt' => 3])
            ->push(['attempt' => 4])
            ->push(['attempt' => 5])
            ->push(['attempt' => 6])]);
        $manager = $this->manager($http);
        $connector = new CacheConnectorStub;

        $this->assertSame(1, $manager->send($connector, new CacheRequestStub)->json('attempt'));
        $this->assertSame(2, $manager->send($connector, new CacheRequestStub)->json('attempt'));
        $this->assertSame(3, $manager->send($connector, (new CacheRequestStub)->disableCaching())->json('attempt'));
        $this->assertSame(4, $manager->send($connector, (new CacheRequestStub)->disableCaching())->json('attempt'));
        $this->assertSame(5, $manager->send($connector, new CachePostRequestStub)->json('attempt'));
        $this->assertSame(6, $manager->send($connector, new CachePostRequestStub)->json('attempt'));
        $http->assertSentCount(6);
    }

    public function testRequestCacheSettingsOverrideConnectorSettings(): void
    {
        $defaultRepository = new Repository(new ArrayStore);
        $requestRepository = new Repository(new ArrayStore);
        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->with('request-store')->andReturn($requestRepository);
        $cache->shouldReceive('store')->with('connector-store')->andReturn($defaultRepository);
        $http = $this->http();
        $http->fake(['*' => Factory::response(['ok' => true])]);
        $manager = $this->manager($http, $cache);
        $connector = new CacheableConnectorStub;

        $first = $manager->send($connector, new CacheStoreRequestStub);
        $second = $manager->send($connector, new CacheStoreRequestStub);

        $this->assertFalse($first->isCached());
        $this->assertTrue($second->isCached());
        $http->assertSentCount(1);
    }

    public function testCacheControlsRequireACacheableRequestOrConnector(): void
    {
        $this->expectException(CachingException::class);

        $this->manager($this->http())->send(new CacheConnectorStub, new CacheControlsOnlyRequestStub);
    }

    public function testDefaultKeyCanonicalizesMapsAndSeparatesResponseIdentity(): void
    {
        $key = new CacheKey;
        $connector = new CacheConnectorStub;
        $first = $this->pending($connector, (new CacheRequestStub)
            ->withHeader('X-Order', ['a', 'b']));
        $same = $this->pending($connector, (new CacheRequestStub)
            ->withHeader('x-order', ['a', 'b']));
        $different = $this->pending($connector, (new CacheRequestStub)
            ->withHeader('X-Order', ['b', 'a']));

        $firstKey = $key->make($first, ['verify' => true, 'curl' => [2 => 'b', 1 => 'a']]);

        $this->assertSame($firstKey, $key->make($same, ['curl' => [1 => 'a', 2 => 'b'], 'verify' => true]));
        $this->assertNotSame($firstKey, $key->make($different, ['verify' => true, 'curl' => [2 => 'b', 1 => 'a']]));
        $this->assertNotSame($firstKey, $key->make($first, ['verify' => false, 'curl' => [2 => 'b', 1 => 'a']]));
        $this->assertMatchesRegularExpression('/^saloon:[a-f0-9]{64}$/', $firstKey);
    }

    public function testCustomKeysRemainBoundedAndCacheScopesStayDistinct(): void
    {
        $pendingRequest = $this->pending(new CacheConnectorStub, new CacheRequestStub);
        $key = new CacheKey;
        $custom = str_repeat('secret-account-', 100);

        $tenantA = $key->make($pendingRequest, [], $custom, 'tenant-a');
        $tenantB = $key->make($pendingRequest, [], $custom, 'tenant-b');

        $this->assertNotSame($tenantA, $tenantB);
        $this->assertSame(71, strlen($tenantA));
        $this->assertStringNotContainsString('secret-account', $tenantA);
        $this->assertStringNotContainsString('tenant-a', $tenantA);
    }

    public function testDefaultBodyIdentityRestoresSeekableStreamsAndRejectsUnsafeInputs(): void
    {
        $request = new CacheStreamRequestStub(Utils::streamFor('payload'));
        $pendingRequest = $this->pending(new CacheConnectorStub, $request);
        $pendingRequest->preparedBody()?->seek(2);

        (new CacheKey)->make($pendingRequest, []);

        $this->assertSame(2, $pendingRequest->preparedBody()?->tell());

        $nonSeekable = m::mock(StreamInterface::class);
        $nonSeekable->shouldReceive('isSeekable')->andReturn(false);
        $unsafe = $this->pending(new CacheConnectorStub, new CacheStreamRequestStub($nonSeekable));

        try {
            (new CacheKey)->make($unsafe, []);
            $this->fail('A non-seekable default cache body was accepted.');
        } catch (BodyException) {
            $this->addToAssertionCount(1);
        }

        $this->assertMatchesRegularExpression(
            '/^saloon:[a-f0-9]{64}$/',
            (new CacheKey)->make($unsafe, [], 'custom-key'),
        );
    }

    public function testSinkAndUnrepresentableIdentityOptionsAreRejected(): void
    {
        $pendingRequest = $this->pending(new CacheConnectorStub, new CacheRequestStub);

        foreach ([['sink' => '/tmp/result'], ['curl' => [1 => new stdClass]]] as $options) {
            try {
                (new CacheKey)->make($pendingRequest, $options);
                $this->fail('An unsafe cache identity option was accepted.');
            } catch (CachingException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            (new CacheKey)->make($pendingRequest, ['sink' => '/tmp/result'], 'custom-key');
            $this->fail('A cached sink was accepted with a custom cache key.');
        } catch (CachingException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Create an isolated HTTP factory.
     */
    protected function http(): Factory
    {
        $http = new Factory;
        $http->registerConnection('saloon');

        return $http;
    }

    /**
     * Create a Saloon manager with an array cache store.
     */
    protected function manager(
        Factory $http,
        ?CacheFactory $cache = null,
        ?Dispatcher $events = null,
        ?Sender $sender = null,
    ): SaloonManager {
        $cache ??= m::mock(CacheFactory::class);

        $repository = new Repository(new ArrayStore);
        $cache->shouldReceive('store')->with(null)->andReturn($repository)->byDefault();
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')->with('saloon.connection.name')->andReturn('saloon');
        $config->shouldReceive('get')->with('saloon.cache.store')->andReturn(null);

        return new SaloonManager(
            $sender ?? new Sender($http, $config),
            $cache,
            m::mock(RateLimiter::class),
            $config,
            $events ?? new Dispatcher,
        );
    }

    /**
     * Create a finalized pending request for cache-key tests.
     */
    protected function pending(Connector $connector, Request $request): PendingRequest
    {
        return (new PendingRequest(
            $connector,
            $request,
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        ))->applyAuthentication()->finalizeUri()->prepareBody();
    }
}

class CacheConnectorStub extends Connector
{
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }
}

class CacheableConnectorStub extends CacheConnectorStub implements Cacheable
{
    public function cacheFor(): int
    {
        return 60;
    }

    public function cacheStore(): string
    {
        return 'connector-store';
    }
}

class CacheRequestStub extends Request implements Cacheable
{
    use HasCaching;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/users';
    }

    public function cacheFor(): DateInterval|DateTimeInterface|int
    {
        return 60;
    }
}

class CacheStoreRequestStub extends CacheRequestStub
{
    public function cacheStore(): UnitEnum|string|null
    {
        return 'request-store';
    }
}

class CachePostRequestStub extends CacheRequestStub
{
    protected Method $method = Method::POST;
}

class CacheControlsOnlyRequestStub extends Request
{
    use HasCaching;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/users';
    }
}

class CacheStreamRequestStub extends CacheRequestStub
{
    public function __construct(protected StreamInterface $stream)
    {
        $this->withBody($stream);
    }
}

class CountingCacheSender extends Sender
{
    public int $transportResolutions = 0;

    public function resolveTransport(PendingRequest $pendingRequest): array
    {
        ++$this->transportResolutions;

        return parent::resolveTransport($pendingRequest);
    }
}
