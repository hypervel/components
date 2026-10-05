<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Cache\Feature;

use DateInterval;
use DateTimeInterface;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
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
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Cache\Fixtures\Connectors\CachedConnector;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\AllowedCachedPostRequest;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\BodyCacheKeyRequest;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\CachedConnectorRequest;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\CachedPostRequest;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\CachedUserRequest;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\CachedUserRequestOnCachedConnector;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\CachedUserRequestWithoutCacheable;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\CustomKeyCachedUserRequest;
use Hypervel\Tests\Saloon\Cache\Fixtures\Requests\ShortLivedCachedUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Mockery as m;
use Psr\Http\Message\StreamInterface;
use stdClass;

// Requests choose a framework cache store with cacheStore() in place of the plugin's cache drivers.
// REMOVED: Feature/PsrCacheDriverTest - the plugin's PSR cache adapter is not included. Other caches are added as
// framework cache stores. Its miss, hit and single-entry assertions are covered by
// testARequestWithTheHasCachingTraitWillCacheTheResponse.
class CacheTest extends TestCase
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
        $app->make('config')->set('cache.stores.custom', ['driver' => 'array']);
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    // REMOVED: "a request with the HasCaching trait will cache the response with a real request" - moved to
    // Integration/Saloon/Cache/Feature/CacheTest, which sends it to the engine test server.

    public function testARequestWithTheHasCachingTraitWillCacheTheResponse(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 201, ['X-Howdy' => 'Yeehaw']),
        ]);

        $responseA = TestConnector::make()->send(new CachedUserRequest, $mockClient);

        $this->assertFalse($responseA->isCached());
        $this->assertTrue($responseA->isMocked());
        $this->assertSame(201, $responseA->status());
        $this->assertSame(['name' => 'Sam'], $responseA->json());
        $this->assertSame('Yeehaw', $responseA->header('X-Howdy'));

        // Now send a response without the mock middleware, and it should be cached!
        // We'll also pass the mock client in here to ensure it's being ignored

        $responseB = TestConnector::make()->send(new CachedUserRequest, $mockClient);

        $this->assertTrue($responseB->isFaked());
        $this->assertTrue($responseB->isCached());
        $this->assertFalse($responseB->isMocked());
        $this->assertSame(201, $responseB->status());
        $this->assertSame(['name' => 'Sam'], $responseB->json());
        $this->assertSame('Yeehaw', $responseB->header('X-Howdy'));
        $this->assertCount(1, Cache::store()->getStore()->all());
    }

    public function testARequestWithTheHasCachingTraitWillCacheTheResponseWithStringBody(): void
    {
        $mockClient = new MockClient([
            MockResponse::make('<p>Hi</p>', 201, ['X-Howdy' => 'Yeehaw']),
        ]);

        $responseA = TestConnector::make()->send(new CachedUserRequest, $mockClient);

        $this->assertFalse($responseA->isCached());
        $this->assertSame(201, $responseA->status());
        $this->assertSame('<p>Hi</p>', $responseA->body());
        $this->assertSame('Yeehaw', $responseA->header('X-Howdy'));

        // Now send a response without the mock middleware, and it should be cached!

        $responseB = TestConnector::make()->send(new CachedUserRequest);

        $this->assertTrue($responseB->isFaked());
        $this->assertTrue($responseB->isCached());
        $this->assertSame(201, $responseB->status());
        $this->assertSame('<p>Hi</p>', $responseB->body());
        $this->assertSame('Yeehaw', $responseB->header('X-Howdy'));
    }

    // Upstream caches only GET and OPTIONS by default; HEAD and QUERY are also cacheable here.
    public function testItWontCacheOnAnythingOtherThanGetHeadOptionsAndQuery(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Gareth']),
        ]);

        $responseA = TestConnector::make()->send(new CachedPostRequest, $mockClient);

        $this->assertFalse($responseA->isCached());
        $this->assertSame(['name' => 'Sam'], $responseA->json());

        $responseB = TestConnector::make()->send(new CachedPostRequest, $mockClient);

        $this->assertFalse($responseB->isCached());
        $this->assertSame(['name' => 'Gareth'], $responseB->json());
    }

    public function testItWillCachePostRequestsIfYouCustomiseTheCacheableMethodsMethod(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $responseA = TestConnector::make()->send(new AllowedCachedPostRequest, $mockClient);

        $this->assertFalse($responseA->isCached());
        $this->assertSame(['name' => 'Sam'], $responseA->json());

        $responseB = TestConnector::make()->send(new AllowedCachedPostRequest, $mockClient);

        $this->assertTrue($responseB->isCached());
        $this->assertSame(['name' => 'Sam'], $responseB->json());
    }

    // Upstream's "a response will not be cached if the response was not 2xx". Redirects are cached there too, so this is
    // named for the statuses it covers.
    public function testAResponseWillNotBeCachedIfTheResponseWasAClientOrServerError(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam'], 422),
            MockResponse::make(['name' => 'Gareth'], 500),
        ]);

        $responseA = TestConnector::make()->send(new CachedUserRequest, $mockClient);

        $this->assertFalse($responseA->isCached());
        $this->assertSame(['name' => 'Sam'], $responseA->json());
        $this->assertSame(422, $responseA->status());

        $responseB = TestConnector::make()->send(new CachedUserRequest, $mockClient);

        $this->assertFalse($responseB->isCached());
        $this->assertSame(['name' => 'Gareth'], $responseB->json());
        $this->assertSame(500, $responseB->status());
    }

    public function testARedirectResponseIsCached(): void
    {
        $mockClient = new MockClient([
            MockResponse::make('', 302, ['Location' => 'https://example.com/moved']),
        ]);

        TestConnector::make()->send(new CachedUserRequest, $mockClient);

        $response = TestConnector::make()->send(new CachedUserRequest);

        $this->assertTrue($response->isCached());
        $this->assertSame(302, $response->status());
        $this->assertSame('https://example.com/moved', $response->header('Location'));
    }

    // Upstream asserts the hashed file name. Cache keys are hashed with the cache scope here, so this checks that the
    // custom key replaces the request identity: a different query string still reaches the cached response.
    public function testACustomCacheKeyCanBeProvidedOnTheResponse(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        TestConnector::make()->send(new CustomKeyCachedUserRequest, $mockClient);

        $response = TestConnector::make()->send((new CustomKeyCachedUserRequest)->withQueryParameters(['page' => 2]));

        $this->assertTrue($response->isCached());
        $this->assertSame(['name' => 'Sam'], $response->json());
    }

    public function testQueryParametersAreUsedInTheCacheKey(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Sam']),
        ]);

        $requestA = (new CachedUserRequest)->withQueryParameters(['name' => 'Sam']);
        $responseA = TestConnector::make()->send($requestA, $mockClient);

        $this->assertFalse($responseA->isCached());
        $this->assertSame(['name' => 'Sam'], $responseA->json());
        $this->assertSame(200, $responseA->status());

        $requestB = (new CachedUserRequest)->withQueryParameters(['name' => 'Sam']);
        $responseB = TestConnector::make()->send($requestB);

        $this->assertTrue($responseB->isCached());
        $this->assertSame(['name' => 'Sam'], $responseB->json());
        $this->assertSame(200, $responseB->status());

        $requestC = new CachedUserRequest;
        $responseC = TestConnector::make()->send($requestC, $mockClient);

        $this->assertFalse($responseC->isCached());
    }

    public function testBodyCanBeUsedInTheCacheKey(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Gareth']),
        ]);

        $requestA = (new BodyCacheKeyRequest)->withData([
            'name' => 'Sam',
            'expiry' => '10 hours',
        ]);

        $responseA = TestConnector::make()->send($requestA, $mockClient);

        $this->assertFalse($responseA->isCached());
        $this->assertSame(['name' => 'Sam'], $responseA->json());
        $this->assertSame(200, $responseA->status());

        $requestB = (new BodyCacheKeyRequest)->withData([
            'name' => 'Sam',
            'expiry' => '10 hours',
        ]);

        $responseB = TestConnector::make()->send($requestB, $mockClient);

        $this->assertTrue($responseB->isCached());
        $this->assertSame(['name' => 'Sam'], $responseB->json());
        $this->assertSame(200, $responseB->status());
    }

    public function testYouWillNotReceiveACachedResponseIfTheResponseHasExpired(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Michael']),
        ]);

        $connector = new TestConnector;

        $requestA = new ShortLivedCachedUserRequest;
        $responseA = $connector->send($requestA, $mockClient);

        $this->assertFalse($responseA->isCached());
        $this->assertSame(['name' => 'Sam'], $responseA->json());

        $requestB = new ShortLivedCachedUserRequest;
        $responseB = $connector->send($requestB);

        $this->assertTrue($responseB->isCached());
        $this->assertSame(['name' => 'Sam'], $responseB->json());

        $this->travel(3)->seconds();

        $requestC = new ShortLivedCachedUserRequest;
        $responseC = $connector->send($requestC, $mockClient);

        $this->assertFalse($responseC->isCached());
        $this->assertSame(['name' => 'Michael'], $responseC->json());
    }

    public function testYouCanDefineACacheOnTheConnectorAndItReturnsACachedResponse(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $connector = new CachedConnector;

        $requestA = new CachedConnectorRequest;
        $responseA = $connector->send($requestA, $mockClient);

        $this->assertFalse($responseA->isCached());
        $this->assertSame(['name' => 'Sam'], $responseA->json());

        $requestB = new CachedConnectorRequest;
        $responseB = $connector->send($requestB);

        $this->assertTrue($responseB->isCached());
        $this->assertSame(['name' => 'Sam'], $responseB->json());
    }

    public function testIfARequestHasCacheConfigurationThenItWillTakePriorityOverTheConnectors(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $connector = new CachedConnector;

        $requestA = new CachedUserRequestOnCachedConnector;
        $responseA = $connector->send($requestA, $mockClient);

        $this->assertFalse($responseA->isCached());
        $this->assertSame(['name' => 'Sam'], $responseA->json());

        $requestB = new CachedUserRequestOnCachedConnector;
        $responseB = $connector->send($requestB);

        $this->assertTrue($responseB->isCached());
        $this->assertSame(['name' => 'Sam'], $responseB->json());

        $this->assertCount(1, Cache::store('custom')->getStore()->all());
        $this->assertCount(0, Cache::store()->getStore()->all());
    }

    public function testYouCanDisableTheCache(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Michael']),
        ]);

        $connector = new TestConnector;

        $requestA = new CachedUserRequest;
        $responseA = $connector->send($requestA, $mockClient);

        $this->assertFalse($responseA->isCached());
        $this->assertSame(['name' => 'Sam'], $responseA->json());

        $requestB = new CachedUserRequest;
        $responseB = $connector->send($requestB);

        $this->assertTrue($responseB->isCached());
        $this->assertSame(['name' => 'Sam'], $responseB->json());

        $requestC = new CachedUserRequest;
        $requestC->disableCaching();

        $responseC = $connector->send($requestC, $mockClient);

        $this->assertFalse($responseC->isCached());
        $this->assertSame(['name' => 'Michael'], $responseC->json());
    }

    public function testCacheCanBeInvalidated(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Teo']),
        ]);

        $connector = new TestConnector;

        $requestA = new CachedUserRequest;
        $responseA = $connector->send($requestA, $mockClient);

        $this->assertFalse($responseA->isCached());
        $this->assertSame(['name' => 'Sam'], $responseA->json());

        $requestB = new CachedUserRequest;
        $responseB = $connector->send($requestB);

        // The response should now be cached...

        $this->assertTrue($responseB->isCached());
        $this->assertSame(['name' => 'Sam'], $responseB->json());

        $requestC = new CachedUserRequest;
        $requestC->invalidateCache();
        $responseC = $connector->send($requestC, $mockClient);

        $this->assertFalse($responseC->isCached());
        $this->assertSame(['name' => 'Teo'], $responseC->json());

        // Now just make sure that the new response is cached...

        $requestD = new CachedUserRequest;
        $responseD = $connector->send($requestD);

        $this->assertTrue($responseD->isCached());
        $this->assertSame(['name' => 'Teo'], $responseD->json());
    }

    public function testItThrowsAnExceptionIfYouUseTheHasCachingTraitWithoutTheCacheableInterface(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['name' => 'Sam']),
        ]);

        $connector = new TestConnector;
        $request = new CachedUserRequestWithoutCacheable;

        $this->expectException(CachingException::class);
        $this->expectExceptionMessage('The request or connector must implement [Hypervel\Saloon\Cache\Contracts\Cacheable] when using request cache controls.');

        $connector->send($request, $mockClient);
    }

    public function testAResponseRejectedByAFailureHookIsNotCachedForRetries(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['status' => 'pending']),
            MockResponse::make(['status' => 'complete']),
        ]);

        $response = TestConnector::make()->send((new PendingStatusCachedRequest)->retry(2), $mockClient);

        $this->assertFalse($response->isCached());
        $this->assertSame(['status' => 'complete'], $response->json());
        $this->assertTrue(TestConnector::make()->send(new PendingStatusCachedRequest)->isCached());
    }

    public function testAFakeResponseFromRequestMiddlewareBypassesTheCache(): void
    {
        $connector = new TestConnector;
        $connector->send(new CachedUserRequest, new MockClient([MockResponse::make(['name' => 'Sam'])]));

        $request = new CachedUserRequest;
        $request->middleware()->onRequest(fn (): MockResponse => MockResponse::make(['name' => 'Middleware']));

        $response = $connector->send($request);

        $this->assertFalse($response->isCached());
        $this->assertSame(['name' => 'Middleware'], $response->json());
        $this->assertSame(['name' => 'Sam'], $connector->send(new CachedUserRequest)->json());
    }

    public function testAMockClientWithoutCacheBypassesReadsWritesAndInvalidation(): void
    {
        $connector = new TestConnector;
        $connector->send(new CachedUserRequest, new MockClient([MockResponse::make(['name' => 'Sam'])]));
        $mockClient = (new MockClient([
            MockResponse::make(['name' => 'Alex']),
            MockResponse::make(['name' => 'Taylor']),
        ]))->withoutCache();

        $uncached = $connector->send(new CachedUserRequest, $mockClient);
        $notInvalidated = $connector->send((new CachedUserRequest)->invalidateCache(), $mockClient);
        $cached = $connector->send(new CachedUserRequest);

        $this->assertFalse($uncached->isCached());
        $this->assertSame(['name' => 'Alex'], $uncached->json());
        $this->assertSame(['name' => 'Taylor'], $notInvalidated->json());
        $this->assertTrue($cached->isCached());
        $this->assertSame(['name' => 'Sam'], $cached->json());
    }

    public function testCacheHitsAreNotRecordedAsSentRequests(): void
    {
        $mockClient = new MockClient([MockResponse::make(['name' => 'Sam'])]);
        $connector = new TestConnector;

        $mocked = $connector->send(new CachedUserRequest, $mockClient);
        $cached = $connector->send(new CachedUserRequest, $mockClient);

        $this->assertTrue($cached->isCached());
        $mockClient->assertSentCount(1);
        $this->assertSame([$mocked], $mockClient->recorded()->all());
    }

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
        $http->fake(function (HttpRequest $request) use (&$requests): PromiseInterface {
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

    public function testRequestTypeCanOptOutOfConnectorCachingWithoutCacheControls(): void
    {
        $http = $this->http();
        $http->fake(['*' => $http->sequence()->push(['version' => 1])->push(['version' => 2])]);
        $manager = $this->manager($http);
        $connector = new CacheableConnectorStub;
        $request = new class extends Request {
            protected Method $method = Method::GET;

            /**
             * Resolve the uncached endpoint.
             */
            public function resolveEndpoint(): string
            {
                return '/live';
            }

            /**
             * Keep this request type out of connector caching.
             */
            public function cachingEnabled(): bool
            {
                return false;
            }
        };

        $first = $manager->send($connector, $request);
        $second = $manager->send($connector, $request);

        $this->assertSame(1, $first->json('version'));
        $this->assertSame(2, $second->json('version'));
        $this->assertFalse($first->isCached());
        $this->assertFalse($second->isCached());
        $http->assertSentCount(2);
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

        $firstCookie = $this->pending($connector, (new CacheRequestStub)->withCookie(new SetCookie([
            'Name' => 'session', 'Value' => 'secret', 'Domain' => 'api.example.com', 'Path' => '/',
        ])));
        $differentCookie = $this->pending($connector, (new CacheRequestStub)->withCookie(new SetCookie([
            'Name' => 'session', 'Value' => 'secret', 'Domain' => 'api.example.com', 'Path' => '/admin',
        ])));

        $this->assertNotSame($key->make($firstCookie, []), $key->make($differentCookie, []));
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
    protected function manager(Factory $http, ?Dispatcher $events = null, ?Sender $sender = null): SaloonManager
    {
        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->with(null)->andReturn(new Repository(new ArrayStore));
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

class PendingStatusCachedRequest extends CachedUserRequest
{
    /**
     * Treat a pending status as a failed request.
     */
    public function hasRequestFailed(Response $response): ?bool
    {
        return $response->json('status') === 'pending' ? true : null;
    }
}

class CacheConnectorStub extends Connector
{
    /**
     * Resolve the integration base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }
}

class CacheableConnectorStub extends CacheConnectorStub implements Cacheable
{
    /**
     * Get the cache duration.
     */
    public function cacheFor(): int
    {
        return 60;
    }

    /**
     * Get the cache store name.
     */
    public function cacheStore(): string
    {
        return 'connector-store';
    }
}

class CacheRequestStub extends Request implements Cacheable
{
    use HasCaching;

    protected Method $method = Method::GET;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users';
    }

    /**
     * Get the cache duration.
     */
    public function cacheFor(): DateInterval|DateTimeInterface|int
    {
        return 60;
    }
}

class CacheStreamRequestStub extends CacheRequestStub
{
    /**
     * Create a request with a stream body.
     */
    public function __construct(protected StreamInterface $stream)
    {
        $this->withBody($stream);
    }
}

class CountingCacheSender extends Sender
{
    public int $transportResolutions = 0;

    /**
     * Resolve the transport and count the resolution.
     */
    public function resolveTransport(PendingRequest $pendingRequest): array
    {
        ++$this->transportResolutions;

        return parent::resolveTransport($pendingRequest);
    }
}
