<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use DateInterval;
use DateTimeInterface;
use Generator;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
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
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\RateLimit\Traits\HasRateLimits;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\parallel;

// Pools send through coroutines, so send() returns the successful responses instead of a promise to wait on, and
// handlers record what they receive for assertions afterwards. Connectors take no mock client, so the global fake
// stands in for upstream's connector client.
// REMOVED: Unit/AsyncRequestTest - sendAsync() is not ported. Its success, error-response and connection-error cases
// map to Feature/PoolTest, and its then() chaining cases only test promises.
class PoolTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testAcceptsAnArrayForRequests(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Charlotte']),
            MockResponse::make(['name' => 'Mantas']),
        ]);

        $connector = new TestConnector;
        $handled = [];

        $requests = [
            new UserRequest,
            new UserRequest,
            new UserRequest,
        ];

        $pool = $connector->pool($requests);

        $pool->setConcurrency(5);

        $pool->withResponseHandler(function (Response $response, int $index) use (&$handled): void {
            $handled[$index] = $response->request();
        });

        $pool->send();

        ksort($handled);

        $this->assertSame($requests, $handled);
    }

    public function testAcceptsAnArrayForAliasedRequests(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Charlotte']),
            MockResponse::make(['name' => 'Mantas']),
        ]);

        $connector = new TestConnector;
        $handled = [];

        $requests = [
            'a' => new UserRequest,
            'b' => new UserRequest,
            'c' => new UserRequest,
        ];

        $pool = $connector->pool($requests);

        $pool->setConcurrency(5);

        $pool->withResponseHandler(function (Response $response, string $name) use (&$handled): void {
            $handled[$name] = $response->request();
        });

        $pool->send();

        ksort($handled);

        $this->assertSame($requests, $handled);
    }

    public function testAcceptsAGeneratorForRequests(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Charlotte']),
            MockResponse::make(['name' => 'Mantas']),
        ]);

        $connector = new TestConnector;
        $handled = [];

        $requests = new Collection;

        $generatorCallback = function () use ($requests): Generator {
            for ($i = 0; $i < 3; ++$i) {
                $request = new UserRequest;
                $requests->put($i, $request);

                yield $i => $request;
            }
        };

        $this->assertIsCallable($generatorCallback);
        $this->assertInstanceOf(Generator::class, $generatorCallback());

        $pool = $connector->pool($generatorCallback());
        $pool->setConcurrency(5);
        $pool->withResponseHandler(function (Response $response, int $index) use (&$handled): void {
            $handled[$index] = $response->request();
        });

        $pool->send();

        ksort($handled);

        $this->assertCount(3, $handled);
        $this->assertSame($requests->all(), $handled);
    }

    public function testAcceptsAGeneratorForAliasedRequests(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Charlotte']),
            MockResponse::make(['name' => 'Mantas']),
        ]);

        $connector = new TestConnector;
        $handled = [];

        $requests = new Collection;

        $generatorCallback = function () use ($requests): Generator {
            foreach (['a', 'b', 'c'] as $name) {
                $request = new UserRequest;
                $requests->put($name, $request);

                yield $name => $request;
            }
        };

        $this->assertIsCallable($generatorCallback);
        $this->assertInstanceOf(Generator::class, $generatorCallback());

        $pool = $connector->pool($generatorCallback());
        $pool->setConcurrency(5);
        $pool->withResponseHandler(function (Response $response, string $name) use (&$handled): void {
            $handled[$name] = $response->request();
        });

        $pool->send();

        ksort($handled);

        $this->assertSame(['a', 'b', 'c'], $requests->keys()->all());
        $this->assertSame($requests->all(), $handled);
    }

    // Request callbacks run when the pool is sent rather than when it is created, so handlers read the requests the
    // callback builds by reference.
    public function testAcceptsACallbackThatReturnsAnArrayForRequests(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Charlotte']),
            MockResponse::make(['name' => 'Mantas']),
        ]);

        $connector = new TestConnector;
        $handled = [];

        $requests = new Collection;

        $arrayCallback = function () use (&$requests): array {
            $requests = $requests->merge([
                new UserRequest,
                new UserRequest,
                new UserRequest,
            ]);

            return $requests->all();
        };

        $this->assertIsCallable($arrayCallback);
        $this->assertIsArray($requests->all());

        $pool = $connector->pool($arrayCallback);

        $pool->setConcurrency(5);

        $pool->withResponseHandler(function (Response $response, int $index) use (&$handled): void {
            $handled[$index] = $response->request();
        });

        $pool->send();

        ksort($handled);

        $this->assertCount(3, $handled);
        $this->assertSame($requests->all(), $handled);
    }

    public function testAcceptsACallbackThatReturnsAnArrayForAliasedRequests(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Charlotte']),
            MockResponse::make(['name' => 'Mantas']),
        ]);

        $connector = new TestConnector;
        $handled = [];
        $callbackConnector = null;

        $requests = new Collection;

        $arrayCallback = function (Connector $received) use (&$requests, &$callbackConnector): array {
            $callbackConnector = $received;

            $requests = $requests->merge([
                'a' => new UserRequest,
                'b' => new UserRequest,
                'c' => new UserRequest,
            ]);

            return $requests->all();
        };

        $this->assertIsCallable($arrayCallback);
        $this->assertIsArray($requests->all());

        $pool = $connector->pool($arrayCallback);
        $pool->setConcurrency(5);
        $pool->withResponseHandler(function (Response $response, string $name) use (&$handled): void {
            $handled[$name] = $response->request();
        });

        $pool->send();

        ksort($handled);

        $this->assertSame($connector, $callbackConnector);
        $this->assertSame(['a', 'b', 'c'], $requests->keys()->all());
        $this->assertSame($requests->all(), $handled);
    }

    public function testAcceptsACallbackThatReturnsAGeneratorForRequests(): void
    {
        Saloon::fake([
            UserRequest::class => MockResponse::make(['name' => 'Sam']),
        ]);

        $connector = new TestConnector;
        $handled = [];

        $requests = new Collection;

        $generatorCallback = function () use ($requests): Generator {
            for ($i = 0; $i < 3; ++$i) {
                $request = new UserRequest;
                $requests->put($i, $request);

                yield $i => $request;
            }
        };

        $this->assertIsCallable($generatorCallback);
        $this->assertInstanceOf(Generator::class, $generatorCallback());

        $pool = $connector->pool($generatorCallback);
        $pool->setConcurrency(5);
        $pool->withResponseHandler(function (Response $response, int $index) use (&$handled): void {
            $handled[$index] = $response->request();
        });

        $pool->send();

        ksort($handled);

        $this->assertCount(3, $handled);
        $this->assertSame($requests->all(), $handled);

        // The callback produces a fresh generator on every send, so the pool can be sent again.
        $firstRequests = $handled;
        $handled = [];

        $pool->send();

        ksort($handled);

        $this->assertCount(3, $handled);
        $this->assertSame($requests->all(), $handled);
        $this->assertNotSame($firstRequests, $handled);
    }

    public function testAcceptsACallbackThatReturnsAGeneratorForAliasedRequests(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Charlotte']),
            MockResponse::make(['name' => 'Mantas']),
        ]);

        $connector = new TestConnector;
        $handled = [];

        $requests = new Collection;

        $generatorCallback = function () use ($requests): Generator {
            foreach (['a', 'b', 'c'] as $name) {
                $request = new UserRequest;
                $requests->put($name, $request);

                yield $name => $request;
            }
        };

        $this->assertIsCallable($generatorCallback);
        $this->assertInstanceOf(Generator::class, $generatorCallback());

        $pool = $connector->pool($generatorCallback);
        $pool->setConcurrency(5);
        $pool->withResponseHandler(function (Response $response, string $name) use (&$handled): void {
            $handled[$name] = $response->request();
        });

        $pool->send();

        ksort($handled);

        $this->assertSame(['a', 'b', 'c'], $requests->keys()->all());
        $this->assertSame($requests->all(), $handled);
    }

    // Invalid items stop scheduling and are reported after the requests already started have settled.
    public function testThrowsAnExceptionIfAnInvalidItemIsPassedIntoTheIterator(): void
    {
        Saloon::fake([
            MockResponse::make(['name' => 'Sam']),
            MockResponse::make(['name' => 'Charlotte']),
        ]);

        $connector = new TestConnector;

        $pool = $connector->pool([
            new UserRequest,
            new UserRequest,
            new TestConnector,
        ]);

        try {
            $pool->send();
            $this->fail('The invalid pool item was not rejected.');
        } catch (PoolException $exception) {
            $this->assertInstanceOf(InvalidPoolItemException::class, $exception->orchestrationFailure());
            $this->assertSame([0, 1], array_keys($exception->responses()));
        }
    }

    public function testYouCanGetTheRequestsProvidedIntoThePool(): void
    {
        $connector = new TestConnector;

        $requests = [
            new UserRequest,
            new UserRequest,
            new TestConnector,
        ];

        $pool = $connector->pool($requests);
        $iterable = $pool->requests();

        $this->assertIsIterable($iterable);

        foreach ($iterable as $index => $request) {
            $this->assertSame($requests[$index], $request);
        }

        $this->assertSame(2, $index);
    }

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

    public function testProducerFailuresTakePrecedenceAndWaitForStartedChildren(): void
    {
        $sendFailure = new RuntimeException('send failed');
        $producerFailure = new RuntimeException('producer failed');
        $manager = $this->manager();
        $manager->fake([
            PoolFailingRequestStub::class => MockResponse::make()->throw($sendFailure),
            PoolRequestStub::class => MockResponse::make(['ok' => true]),
        ]);
        $connector = new PoolConnectorStub($manager);

        try {
            $connector->pool(function () use ($producerFailure): iterable {
                yield 'failed' => new PoolFailingRequestStub;
                yield 'started' => new PoolRequestStub(1);
                throw $producerFailure;
            })->send();
            $this->fail('The producer failure was not returned.');
        } catch (PoolException $exception) {
            $this->assertSame($producerFailure, $exception->orchestrationFailure());
            $this->assertSame($producerFailure, $exception->getPrevious());
            $this->assertSame(['failed' => $sendFailure], $exception->failures());
            $this->assertSame(['started'], array_keys($exception->responses()));
        }
    }

    public function testIndependentRequestCancellationIsAChildFailureWithoutInvokingTheExceptionHandler(): void
    {
        $manager = $this->manager();
        $requestStarted = new Channel(1);
        $blocker = new Channel(1);
        $childCoroutineId = null;
        $nativeCancellation = null;
        $handlerInvoked = false;
        $outcome = null;
        $manager->fake([
            PoolRequestStub::class => function () use (
                $requestStarted,
                $blocker,
                &$childCoroutineId,
                &$nativeCancellation,
            ): never {
                $childCoroutineId = Coroutine::id();
                $requestStarted->push(true);

                try {
                    $blocker->pop();
                } catch (CanceledException $exception) {
                    $nativeCancellation = $exception;
                    throw $exception;
                }

                throw new RuntimeException('The request was not canceled.');
            },
        ]);
        $connector = new PoolConnectorStub($manager);

        $runner = EngineCoroutine::create(function () use ($connector, &$handlerInvoked, &$outcome): void {
            try {
                $connector->pool(['request' => new PoolRequestStub(1)])
                    ->withExceptionHandler(static function () use (&$handlerInvoked): void {
                        $handlerInvoked = true;
                    })
                    ->send();
            } catch (Throwable $exception) {
                $outcome = $exception;
            }
        });

        $this->assertTrue($requestStarted->pop());
        $this->assertIsInt($childCoroutineId);
        $this->assertTrue(EngineCoroutine::cancelById($childCoroutineId, throwException: true));
        Coroutine::join([$runner->getId()]);

        $this->assertInstanceOf(PoolException::class, $outcome);
        $this->assertInstanceOf(ChildCancellationException::class, $outcome->failures()['request']);
        $this->assertSame($nativeCancellation, $outcome->failures()['request']->getPrevious());
        $this->assertFalse($handlerInvoked);
    }

    public function testResponseHandlerCancellationRetainsTheResponseAsAChildCallbackFailure(): void
    {
        $manager = $this->manager();
        $manager->fake([PoolRequestStub::class => MockResponse::make(['ok' => true])]);
        $callbackStarted = new Channel(1);
        $blocker = new Channel(1);
        $childCoroutineId = null;
        $nativeCancellation = null;
        $outcome = null;
        $connector = new PoolConnectorStub($manager);

        $runner = EngineCoroutine::create(function () use (
            $connector,
            $callbackStarted,
            $blocker,
            &$childCoroutineId,
            &$nativeCancellation,
            &$outcome,
        ): void {
            try {
                $connector->pool(['request' => new PoolRequestStub(1)])
                    ->withResponseHandler(static function () use (
                        $callbackStarted,
                        $blocker,
                        &$childCoroutineId,
                        &$nativeCancellation,
                    ): void {
                        $childCoroutineId = Coroutine::id();
                        $callbackStarted->push(true);

                        try {
                            $blocker->pop();
                        } catch (CanceledException $exception) {
                            $nativeCancellation = $exception;
                            throw $exception;
                        }
                    })
                    ->send();
            } catch (Throwable $exception) {
                $outcome = $exception;
            }
        });

        $this->assertTrue($callbackStarted->pop());
        $this->assertIsInt($childCoroutineId);
        $this->assertTrue(EngineCoroutine::cancelById($childCoroutineId, throwException: true));
        Coroutine::join([$runner->getId()]);

        $this->assertInstanceOf(PoolException::class, $outcome);
        $this->assertInstanceOf(ChildCancellationException::class, $outcome->callbackFailures()['request']);
        $this->assertSame($nativeCancellation, $outcome->callbackFailures()['request']->getPrevious());
        $this->assertSame(['request'], array_keys($outcome->responses()));
    }

    public function testProducerCancellationCancelsStartedRequestsAndEscapesExactly(): void
    {
        $manager = $this->manager();
        $requestStarted = new Channel(1);
        $requestBlocker = new Channel(1);
        $producerSuspended = new Channel(1);
        $producerBlocker = new Channel(1);
        $childCancellation = null;
        $parentCancellation = null;
        $manager->fake([
            PoolRequestStub::class => function () use ($requestStarted, $requestBlocker, &$childCancellation): never {
                $requestStarted->push(true);

                try {
                    $requestBlocker->pop();
                } catch (CanceledException $exception) {
                    $childCancellation = $exception;
                    throw $exception;
                }

                throw new RuntimeException('The request was not canceled.');
            },
        ]);
        $connector = new PoolConnectorStub($manager);

        $runner = EngineCoroutine::create(function () use (
            $connector,
            $producerSuspended,
            $producerBlocker,
            &$parentCancellation,
        ): void {
            try {
                $connector->pool((static function () use ($producerSuspended, $producerBlocker): iterable {
                    yield 'request' => new PoolRequestStub(1);
                    $producerSuspended->push(true);
                    $producerBlocker->pop();
                })())->send();
            } catch (CanceledException $exception) {
                $parentCancellation = $exception;
            }
        });

        $this->assertTrue($requestStarted->pop());
        $this->assertTrue($producerSuspended->pop());
        $this->assertTrue(EngineCoroutine::cancelById($runner->getId(), throwException: true));

        $this->assertInstanceOf(CanceledException::class, $parentCancellation);
        $this->assertInstanceOf(CanceledException::class, $childCancellation);
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
        $http->fake(function (): PromiseInterface {
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
     * Send a request through the isolated manager.
     */
    public function send(Request $request, ?MockClient $mockClient = null): Response
    {
        return $this->manager->send($this, $request, $mockClient);
    }
}

class PoolRequestStub extends Request
{
    protected Method $method = Method::GET;

    /**
     * Create a request identified by a query parameter.
     */
    public function __construct(int $id)
    {
        $this->withQueryParameters(['id' => $id]);
    }

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/users';
    }
}

class PoolFailingRequestStub extends Request
{
    protected Method $method = Method::GET;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/failure';
    }
}

class ScopedPoolRequestStub extends PoolRequestStub implements Cacheable
{
    use HasCaching;
    use HasRateLimits;

    /**
     * Define the cache lifetime in seconds.
     */
    public function cacheFor(): DateInterval|DateTimeInterface|int
    {
        return 60;
    }

    /**
     * Resolve the request's rate limits.
     *
     * @return list<AdmissionPolicy>
     */
    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [Limit::perMinute(10)->by((string) $pendingRequest->request()->queryParameters()['id'])];
    }
}
