<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\KeyResolver;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\RateLimiter\WorkerArrayStore;
use Hypervel\Saloon\Cache\Contracts\Cacheable;
use Hypervel\Saloon\Cache\Traits\HasCaching;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Exceptions\InvalidPoolItemException;
use Hypervel\Saloon\Exceptions\PoolException;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\RateLimit\Traits\HasRateLimits;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class PoolTest extends TestCase
{
    public function testPoolBoundsConcurrencyPreservesInputOrderAndPropagatesContext(): void
    {
        CoroutineContext::set('pool-tenant', 'tenant-a');
        $active = 0;
        $maximumActive = 0;
        $handlerContexts = [];
        $manager = $this->manager();
        $manager->fake([
            PoolRequestStub::class => function (PendingRequest $pendingRequest) use (&$active, &$maximumActive): MockResponse {
                ++$active;
                $maximumActive = max($maximumActive, $active);
                $id = (int) $pendingRequest->request()->queryParameters()['id'];
                usleep((4 - $id) * 2000);
                --$active;

                return MockResponse::make(['id' => $id]);
            },
        ]);
        $connector = new PoolConnectorStub($manager);
        $requests = [
            'first' => new PoolRequestStub(1),
            'second' => new PoolRequestStub(2),
            'third' => new PoolRequestStub(3),
        ];

        $responses = $connector
            ->pool(fn (Connector $received): array => $received === $connector ? $requests : [], concurrency: 2)
            ->withResponseHandler(function (Response $response, string $key) use (&$handlerContexts): void {
                $handlerContexts[$key] = CoroutineContext::get('pool-tenant');
            })
            ->send();

        $this->assertSame(2, $maximumActive);
        $this->assertSame(['first', 'second', 'third'], array_keys($responses));
        $this->assertSame([1, 2, 3], array_map(
            static fn (Response $response): int => (int) $response->json('id'),
            array_values($responses),
        ));
        ksort($handlerContexts);

        $this->assertSame([
            'first' => 'tenant-a',
            'second' => 'tenant-a',
            'third' => 'tenant-a',
        ], $handlerContexts);
    }

    public function testHandledRequestFailuresAreOmittedAfterEveryChildSettles(): void
    {
        $manager = $this->manager();
        $manager->fake([
            PoolFailingRequestStub::class => MockResponse::make()->throw(new RuntimeException('failed')),
            PoolRequestStub::class => MockResponse::make(['ok' => true]),
        ]);
        $handled = [];
        $connector = new PoolConnectorStub($manager);

        $responses = $connector->pool([
            'failed' => new PoolFailingRequestStub,
            'successful' => new PoolRequestStub(1),
        ])->withExceptionHandler(function (RuntimeException $exception, string $key) use (&$handled): void {
            $handled[$key] = $exception->getMessage();
        })->send();

        $this->assertSame(['failed' => 'failed'], $handled);
        $this->assertSame(['successful'], array_keys($responses));
    }

    public function testPoolExceptionPreservesRequestCallbackAndPartialResults(): void
    {
        $manager = $this->manager();
        $manager->fake([
            PoolFailingRequestStub::class => MockResponse::make()->throw(new RuntimeException('send failed')),
            PoolRequestStub::class => MockResponse::make(['ok' => true]),
        ]);
        $connector = new PoolConnectorStub($manager);

        try {
            $connector->pool([
                'failed' => new PoolFailingRequestStub,
                'successful' => new PoolRequestStub(1),
            ])->withResponseHandler(function (): void {
                throw new RuntimeException('callback failed');
            })->send();
            $this->fail('The pool exception was not thrown.');
        } catch (PoolException $exception) {
            $this->assertSame('send failed', $exception->failures()['failed']->getMessage());
            $this->assertSame('callback failed', $exception->callbackFailures()['successful']->getMessage());
            $this->assertSame(['successful'], array_keys($exception->responses()));
            $this->assertNull($exception->orchestrationFailure());
        }
    }

    public function testInvalidItemsAndProducerFailuresWaitForStartedChildren(): void
    {
        $manager = $this->manager();
        $manager->fake([PoolRequestStub::class => MockResponse::make(['ok' => true])]);
        $connector = new PoolConnectorStub($manager);

        try {
            $connector->pool((function (): iterable {
                yield 'started' => new PoolRequestStub(1);
                yield 'invalid' => new PoolConnectorStub($this->manager());
            })())->send();
            $this->fail('The invalid pool item was not rejected.');
        } catch (PoolException $exception) {
            $this->assertInstanceOf(InvalidPoolItemException::class, $exception->orchestrationFailure());
            $this->assertSame(['started'], array_keys($exception->responses()));
        }

        try {
            $connector->pool(function (): iterable {
                yield 'started' => new PoolRequestStub(1);
                throw new RuntimeException('producer failed');
            })->send();
            $this->fail('The producer failure was not returned.');
        } catch (PoolException $exception) {
            $this->assertSame('producer failed', $exception->orchestrationFailure()?->getMessage());
            $this->assertSame(['started'], array_keys($exception->responses()));
        }
    }

    public function testRepeatedKeysUseTheLaterInputAndProcessDoesNotCollectResponses(): void
    {
        $manager = $this->manager();
        $manager->fake([
            PoolRequestStub::class => fn (PendingRequest $pendingRequest): MockResponse => MockResponse::make([
                'id' => $pendingRequest->request()->queryParameters()['id'],
            ]),
        ]);
        $connector = new PoolConnectorStub($manager);
        $handled = [];

        $responses = $connector->pool((function (): iterable {
            yield 'same' => new PoolRequestStub(1);
            yield 'same' => new PoolRequestStub(2);
        })())->send();
        $connector->pool([
            'first' => new PoolRequestStub(3),
            'second' => new PoolRequestStub(4),
        ])->withResponseHandler(function (Response $response, string $key) use (&$handled): void {
            $handled[$key] = $response->json('id');
        })->process();

        $this->assertSame(2, $responses['same']->json('id'));
        $this->assertSame(['first' => 3, 'second' => 4], $handled);
    }

    public function testConcurrencyMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PoolConnectorStub($this->manager()))->pool(concurrency: 0);
    }

    public function testConcurrentPoolParentsKeepCacheAndRateScopesInTheirChildren(): void
    {
        $http = new Factory;
        $http->registerConnection('saloon');
        $http->fake(function () {
            usleep(5000);

            return Factory::response(['ok' => true]);
        });
        $cacheRepository = new Repository(new ArrayStore);
        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('store')->with(null)->andReturn($cacheRepository);
        $cacheScopes = [];
        $rateScopes = [];
        $limiter = new Limiter(
            new WorkerArrayStore,
            new KeyResolver('saloon-pool-scopes', function () use (&$rateScopes): ?string {
                $scope = CoroutineContext::get('pool-tenant');
                $rateScopes[] = $scope;

                return $scope;
            }),
        );
        $rateLimiter = m::mock(RateLimiter::class);
        $rateLimiter->shouldReceive('store')->with(null)->andReturn($limiter);
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')->with('saloon.connection.name')->andReturn('saloon');
        $config->shouldReceive('get')->with('saloon.cache.store')->andReturn(null);
        $config->shouldReceive('get')->with('saloon.rate_limiter.store')->andReturn(null);
        $manager = new SaloonManager(
            new Sender($http, $config),
            $cache,
            $rateLimiter,
            $config,
            new Dispatcher,
        );
        $manager->resolveCacheScopeUsing(function (PendingRequest $pendingRequest) use (&$cacheScopes): ?string {
            $scope = CoroutineContext::get('pool-tenant');
            $id = (string) $pendingRequest->request()->queryParameters()['id'];
            $cacheScopes[$id] = $scope;

            return $scope;
        });
        $connector = new PoolConnectorStub($manager);

        [$tenantA, $tenantB] = parallel([
            function () use ($connector): array {
                CoroutineContext::set('pool-tenant', 'tenant-a');

                return $connector->pool([
                    'a-1' => new ScopedPoolRequestStub(1),
                    'a-2' => new ScopedPoolRequestStub(2),
                ])->send();
            },
            function () use ($connector): array {
                CoroutineContext::set('pool-tenant', 'tenant-b');

                return $connector->pool([
                    'b-1' => new ScopedPoolRequestStub(3),
                    'b-2' => new ScopedPoolRequestStub(4),
                ])->send();
            },
        ]);

        ksort($cacheScopes);
        $this->assertSame([
            '1' => 'tenant-a',
            '2' => 'tenant-a',
            '3' => 'tenant-b',
            '4' => 'tenant-b',
        ], $cacheScopes);
        $this->assertSame(['a-1', 'a-2'], array_keys($tenantA));
        $this->assertSame(['b-1', 'b-2'], array_keys($tenantB));
        $this->assertNotContains(null, $rateScopes);
        $this->assertEqualsCanonicalizing(['tenant-a', 'tenant-b'], array_values(array_unique($rateScopes)));
        $http->assertSentCount(4);
    }

    /**
     * Create the manager with isolated framework services.
     */
    protected function manager(): SaloonManager
    {
        $http = new Factory;
        $http->registerConnection('saloon');
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')
            ->with('saloon.connection.name')
            ->andReturn('saloon');

        return new SaloonManager(
            new Sender($http, $config),
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
            $config,
            new Dispatcher,
        );
    }
}

class PoolConnectorStub extends Connector
{
    public function __construct(protected SaloonManager $manager)
    {
    }

    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    public function send(Request $request, ?MockClient $mockClient = null): Response
    {
        return $this->manager->send($this, $request, $mockClient);
    }
}

class PoolRequestStub extends Request
{
    protected Method $method = Method::GET;

    public function __construct(int $id)
    {
        $this->withQueryParameters(['id' => $id]);
    }

    public function resolveEndpoint(): string
    {
        return '/users';
    }
}

class PoolFailingRequestStub extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/failure';
    }
}

class ScopedPoolRequestStub extends PoolRequestStub implements Cacheable
{
    use HasCaching;
    use HasRateLimits;

    public function cacheFor(): DateInterval|DateTimeInterface|int
    {
        return 60;
    }

    /** @return list<AdmissionPolicy> */
    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [Limit::perMinute(10)->by((string) $pendingRequest->request()->queryParameters()['id'])];
    }
}
