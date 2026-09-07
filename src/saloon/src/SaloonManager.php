<?php

declare(strict_types=1);

namespace Hypervel\Saloon;

use Closure;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Response as HttpResponse;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Contracts\Decision;
use Hypervel\RateLimiter\Cooldown;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Cache\CacheKey;
use Hypervel\Saloon\Cache\Data\CachedResponse;
use Hypervel\Saloon\Data\RecordedResponse;
use Hypervel\Saloon\Events\SendingSaloonRequest;
use Hypervel\Saloon\Events\SentSaloonRequest;
use Hypervel\Saloon\Exceptions\BodyException;
use Hypervel\Saloon\Exceptions\Request\FatalRequestException;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\Fixture;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\MiddlewarePipeline;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\RateLimit\Exceptions\RateLimitReachedException;
use Hypervel\Support\Sleep;
use InvalidArgumentException;
use UnitEnum;

class SaloonManager
{
    /**
     * The middleware applied to every Saloon request.
     */
    protected MiddlewarePipeline $middleware;

    /**
     * The cache scope resolver.
     *
     * @var null|Closure(PendingRequest): ?string
     */
    protected ?Closure $cacheScopeResolver = null;

    /**
     * The tests-only fixture path override.
     */
    protected ?string $fixturePath = null;

    /**
     * The tests-only missing fixture behavior override.
     */
    protected ?bool $throwOnMissingFixtures = null;

    /**
     * The tests-only global mock client.
     */
    protected ?MockClient $mockClient = null;

    /**
     * Create the Saloon manager.
     */
    public function __construct(
        protected Sender $sender,
        protected CacheFactory $cache,
        protected RateLimiter $rateLimiter,
        protected ConfigRepository $config,
        protected Dispatcher $events,
    ) {
        $this->middleware = new MiddlewarePipeline;
    }

    /**
     * Send a request through a connector.
     *
     * @template TDto
     * @param Request<TDto> $request
     * @return Response<TDto>
     */
    public function send(Connector $connector, Request $request, ?MockClient $mockClient = null): Response
    {
        $attempt = 0;
        $mockClient ??= $request->mockClient() ?? $this->mockClient;

        while (true) {
            ++$attempt;

            $pendingRequest = new PendingRequest(
                $connector,
                $request,
                $this->cache,
                $this->rateLimiter,
            );
            $pendingRequest
                ->applyAuthentication()
                ->bootPlugins();
            $connector->boot($pendingRequest);
            $request->boot($pendingRequest);
            $pendingRequest
                ->mergeMiddleware($this->middleware)
                ->executeRequestPipeline();

            if ($this->events->hasListeners(SendingSaloonRequest::class)) {
                $this->events->dispatch(new SendingSaloonRequest($pendingRequest));
            }

            $pendingRequest
                ->finalizeUri()
                ->prepareBody()
                ->validateCachingConfiguration();

            // Snapshot connection selection and transport options once. Later
            // policy hooks inspect the finalized operation; Sender still reads
            // its URI, headers, cookies, and prepared body directly.
            $transport = $this->sender->resolveTransport($pendingRequest);

            $fixture = null;

            if ($pendingRequest->fakeResponse() === null && $mockClient !== null) {
                $matchedResponse = $mockClient->match($pendingRequest);

                if ($matchedResponse instanceof Fixture) {
                    $fixture = $matchedResponse;
                    $matchedResponse = $matchedResponse->getMockResponse();
                }

                $pendingRequest->setFakeResponse($matchedResponse);
            }

            $response = null;
            $responseFromSender = false;
            $cacheRepository = null;
            $cacheKey = null;

            if ($pendingRequest->fakeResponse() === null && $pendingRequest->isCacheable()) {
                [$cacheRepository, $cacheKey] = $this->resolveCache($pendingRequest, $transport);

                if ($pendingRequest->shouldInvalidateCache()) {
                    $cacheRepository->forget($cacheKey);
                } else {
                    $cachedResponse = $cacheRepository->get($cacheKey);

                    if ($cachedResponse instanceof CachedResponse) {
                        $psrRequest = $pendingRequest->createPsrRequest();
                        $response = $pendingRequest
                            ->createResponse(new HttpResponse($cachedResponse->toPsrResponse()), $psrRequest)
                            ->setCached(true);
                    }
                }
            }

            if ($response === null && $pendingRequest->fakeResponse() === null) {
                $this->enforceRateLimits($pendingRequest);
            }

            $exceptionWasThrown = false;

            try {
                if ($response === null) {
                    if (($fakeResponse = $pendingRequest->fakeResponse()) !== null) {
                        if (($exception = $fakeResponse->getException($pendingRequest)) !== null) {
                            throw $exception;
                        }

                        $psrRequest = $pendingRequest->createPsrRequest();
                        $response = $pendingRequest
                            ->createResponse(new HttpResponse($fakeResponse->createPsrResponse()), $psrRequest)
                            ->setMocked($fakeResponse instanceof MockResponse)
                            ->setFakeResponse($fakeResponse);
                    } else {
                        $this->sleepMilliseconds($pendingRequest->delayMilliseconds() ?? 0);
                        $response = $this->sender->send($pendingRequest, $transport);
                        $responseFromSender = true;
                    }
                }

                if ($responseFromSender && $fixture !== null) {
                    $fixture->store(RecordedResponse::fromResponse($response));
                }

                if ($responseFromSender) {
                    $this->recordRateLimitCooldowns($pendingRequest, $response);
                }

                if ($responseFromSender
                    && $cacheRepository !== null
                    && $cacheKey !== null
                    && $response->successful()) {
                    $cacheRepository->put(
                        $cacheKey,
                        CachedResponse::fromResponse($response),
                        $pendingRequest->cacheFor(),
                    );
                }

                $mockClient?->recordResponse($response);

                if ($this->events->hasListeners(SentSaloonRequest::class)) {
                    $this->events->dispatch(new SentSaloonRequest($pendingRequest, $response));
                }

                $response = $pendingRequest->executeResponsePipeline($response);
                $exception = $response->toException();

                if ($exception === null) {
                    return $response;
                }
            } catch (ConnectionException $exception) {
                $exception = new FatalRequestException($exception, $pendingRequest);
                $pendingRequest->executeFatalPipeline($exception);
            } catch (RequestException $exception) {
                $response = $exception->response();
                $exceptionWasThrown = true;
            }

            $retryPolicy = $pendingRequest->retryPolicy();
            $maximumAttempts = $retryPolicy->maximumAttempts();
            $shouldRetry = $attempt < $maximumAttempts
                && ($retryPolicy->when === null || ($retryPolicy->when)($exception, $pendingRequest));

            if (! $shouldRetry) {
                if ($exception instanceof FatalRequestException
                    || $exceptionWasThrown
                    || ($retryPolicy->throw && $maximumAttempts > 1)) {
                    throw $exception;
                }

                return $response;
            }

            if (! $pendingRequest->restoreAttemptBody()) {
                throw new BodyException(
                    'The request body is not seekable and cannot be retried safely.',
                    previous: $exception,
                );
            }

            $this->sleepMilliseconds($retryPolicy->delayFor($attempt, $exception));
        }
    }

    /**
     * Get the Saloon sender.
     */
    public function sender(): Sender
    {
        return $this->sender;
    }

    /**
     * Get the cache factory.
     */
    public function cache(): CacheFactory
    {
        return $this->cache;
    }

    /**
     * Get the rate limiter manager.
     */
    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    /**
     * Get the global middleware pipeline.
     *
     * Boot-only. Middleware added to this pipeline persists on the manager for
     * the worker lifetime and affects every subsequent Saloon request.
     */
    public function middleware(): MiddlewarePipeline
    {
        return $this->middleware;
    }

    /**
     * Replace the global mock client.
     *
     * Tests only. The client persists on the manager until the test
     * application is destroyed or `clearFake()` is called.
     *
     * @param array<array-key, callable(PendingRequest): (Fixture|MockResponse)|Fixture|MockResponse>|MockClient $responses
     */
    public function fake(array|MockClient $responses = []): MockClient
    {
        return $this->mockClient = $responses instanceof MockClient
            ? $responses
            : new MockClient($responses);
    }

    /**
     * Get the global mock client.
     */
    public function mockClient(): ?MockClient
    {
        return $this->mockClient;
    }

    /**
     * Clear the global mock client.
     *
     * Tests only. This immediately removes the recorder for every subsequent
     * Saloon request in the worker.
     *
     * @return $this
     */
    public function clearFake(): static
    {
        $this->mockClient = null;

        return $this;
    }

    /**
     * Assert that a matching request was sent.
     */
    public function assertSent(string|callable $value): void
    {
        ($this->mockClient ?? new MockClient)->assertSent($value);
    }

    /**
     * Assert that no matching request was sent.
     */
    public function assertNotSent(string|callable $value): void
    {
        ($this->mockClient ?? new MockClient)->assertNotSent($value);
    }

    /**
     * Assert that requests were sent in order.
     *
     * @param list<callable|string> $callbacks
     */
    public function assertSentInOrder(array $callbacks): void
    {
        ($this->mockClient ?? new MockClient)->assertSentInOrder($callbacks);
    }

    /**
     * Assert that nothing was sent.
     */
    public function assertNothingSent(): void
    {
        ($this->mockClient ?? new MockClient)->assertNothingSent();
    }

    /**
     * Assert the number of recorded requests.
     *
     * @param null|class-string<Request> $requestClass
     */
    public function assertSentCount(int $count, ?string $requestClass = null): void
    {
        ($this->mockClient ?? new MockClient)->assertSentCount($count, $requestClass);
    }

    /**
     * Register the cache scope resolver.
     *
     * Boot-only. The callback persists on the manager for the worker lifetime
     * and affects every subsequently cached Saloon request.
     *
     * @param null|Closure(PendingRequest): ?string $resolver
     */
    public function resolveCacheScopeUsing(?Closure $resolver): static
    {
        $this->cacheScopeResolver = $resolver;

        return $this;
    }

    /**
     * Resolve the cache scope for the request.
     */
    public function resolveCacheScope(PendingRequest $pendingRequest): ?string
    {
        return $this->cacheScopeResolver !== null
            ? ($this->cacheScopeResolver)($pendingRequest)
            : null;
    }

    /**
     * Set the fixture storage path.
     *
     * Tests only. The override persists on the manager until the test
     * application is destroyed.
     */
    public function fixturePath(string $path): static
    {
        $this->fixturePath = $path;

        return $this;
    }

    /**
     * Get the fixture storage path.
     */
    public function getFixturePath(): string
    {
        return $this->fixturePath
            ?? $this->config->string(
                'saloon.fixtures.path',
                static fn (): string => base_path('tests/Fixtures/Saloon'),
            );
    }

    /**
     * Configure missing fixture behavior.
     *
     * Tests only. The override persists on the manager until the test
     * application is destroyed.
     */
    public function throwOnMissingFixtures(bool $throw = true): static
    {
        $this->throwOnMissingFixtures = $throw;

        return $this;
    }

    /**
     * Determine if missing fixtures should throw.
     */
    public function throwsOnMissingFixtures(): bool
    {
        return $this->throwOnMissingFixtures
            ?? $this->config->boolean('saloon.fixtures.throw_on_missing', false);
    }

    /**
     * Sleep for a checked number of milliseconds.
     */
    protected function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds < 0 || $milliseconds > intdiv(PHP_INT_MAX, 1000)) {
            throw new InvalidArgumentException('The delay must be a representable non-negative number of milliseconds.');
        }

        if ($milliseconds > 0) {
            Sleep::usleep($milliseconds * 1000);
        }
    }

    /**
     * Resolve the cache store and bounded key for an operation.
     *
     * @param array{connection: string, connectionOptions: array<string, mixed>, requestOptions: array<string, mixed>} $transport
     * @return array{CacheRepository, string}
     */
    protected function resolveCache(PendingRequest $pendingRequest, array $transport): array
    {
        /** @var null|string|UnitEnum $configuredStore */
        $configuredStore = $this->config->get('saloon.cache.store');
        $store = $pendingRequest->cacheStore()
            ?? $configuredStore;
        $repository = $this->cache->store($store);
        $key = (new CacheKey)->make(
            $pendingRequest,
            array_replace_recursive(
                $transport['connectionOptions'],
                $transport['requestOptions'],
            ),
            $pendingRequest->resolveCacheKey(),
            $this->resolveCacheScope($pendingRequest),
        );

        return [$repository, $key];
    }

    /**
     * Enforce connector and request rate limits before transport.
     */
    protected function enforceRateLimits(PendingRequest $pendingRequest): void
    {
        $this->enforceRateLimitsFor($pendingRequest, $pendingRequest->connector());
        $this->enforceRateLimitsFor($pendingRequest, $pendingRequest->request());
    }

    /**
     * Enforce one rate-limited resource.
     */
    protected function enforceRateLimitsFor(PendingRequest $pendingRequest, Connector|Request $resource): void
    {
        if (! $resource->usesRateLimits()) {
            return;
        }

        $policies = $resource->resolveRateLimitPolicies($pendingRequest);

        foreach ($policies as $policy) {
            if (! $policy instanceof AdmissionPolicy) {
                throw new InvalidArgumentException(sprintf(
                    'Rate limit policies must extend [%s].',
                    AdmissionPolicy::class,
                ));
            }

            if ($policy->afterCallback !== null || $policy->responseCallback !== null) {
                throw new InvalidArgumentException(
                    'Saloon rate limit policies may not define after or response callbacks.'
                );
            }
        }

        $limiter = $this->rateLimiterFor($resource);
        $limiterName = 'saloon:' . $resource::class;
        $cooldown = Cooldown::for($resource->resolveRateLimitCooldownKeyFor($pendingRequest));

        while (($result = $limiter->inspect($cooldown, $limiterName))->denied()) {
            $this->waitForRateLimit($resource, $cooldown, $result);
        }

        foreach ($policies as $policy) {
            while (($result = $limiter->consume($policy, $limiterName))->denied()) {
                $this->waitForRateLimit($resource, $policy, $result);
            }
        }
    }

    /**
     * Record connector and request cooldowns from a transport response.
     */
    protected function recordRateLimitCooldowns(PendingRequest $pendingRequest, Response $response): void
    {
        $this->recordRateLimitCooldownFor($pendingRequest, $response, $pendingRequest->connector());
        $this->recordRateLimitCooldownFor($pendingRequest, $response, $pendingRequest->request());
    }

    /**
     * Record one resource's response-derived cooldown.
     */
    protected function recordRateLimitCooldownFor(
        PendingRequest $pendingRequest,
        Response $response,
        Connector|Request $resource,
    ): void {
        if (! $resource->usesRateLimits()
            || ($seconds = $resource->resolveRateLimitCooldownFor($response)) === null) {
            return;
        }

        $this->rateLimiterFor($resource)->block(
            Cooldown::for($resource->resolveRateLimitCooldownKeyFor($pendingRequest)),
            $seconds,
            'saloon:' . $resource::class,
        );
    }

    /**
     * Resolve the framework limiter selected by a resource.
     */
    protected function rateLimiterFor(Connector|Request $resource): Limiter
    {
        /** @var null|string|UnitEnum $configuredStore */
        $configuredStore = $this->config->get('saloon.rate_limiter.store');

        return $this->rateLimiter->store(
            $resource->resolveRateLimitStoreName() ?? $configuredStore,
        );
    }

    /**
     * Wait for a denied policy or throw its result.
     */
    protected function waitForRateLimit(
        Connector|Request $resource,
        AdmissionPolicy|Cooldown $policy,
        Decision $result,
    ): void {
        if (! $resource->shouldWaitForRateLimits()) {
            throw new RateLimitReachedException($policy, $result);
        }

        Sleep::sleep($result->retryAfter());
    }
}
