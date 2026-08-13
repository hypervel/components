<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use Hypervel\Container\Container;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Contracts\Body\BodyRepository;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Saloon\Traits\Bootable;
use Hypervel\Saloon\Traits\HandlesPsrRequest;
use Hypervel\Saloon\Traits\Makeable;
use Hypervel\Saloon\Traits\ManagesExceptions;
use Hypervel\Saloon\Traits\Request\CreatesDtoFromResponse;
use Hypervel\Saloon\Traits\Responses\HasCustomResponses;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use Throwable;
use UnitEnum;

/** @template TDto */
abstract class Connector
{
    /** @use CreatesDtoFromResponse<TDto> */
    use CreatesDtoFromResponse;

    use Bootable;
    use Conditionable;
    use HandlesPsrRequest;
    use HasCustomResponses;
    use Macroable;
    use Makeable;
    use ManagesExceptions;

    /**
     * Resolve the integration base URL.
     */
    abstract public function resolveBaseUrl(): string;

    /**
     * Send a request through this connector.
     *
     * @template TRequestDto
     * @param Request<TRequestDto> $request
     * @return Response<TRequestDto>
     */
    public function send(Request $request, ?MockClient $mockClient = null): Response
    {
        /** @var SaloonManager $manager */
        $manager = Container::getInstance()->make('saloon');

        return $manager->send($this, $request, $mockClient);
    }

    /**
     * Create a bounded request pool.
     *
     * @param callable(Connector): iterable<array-key, Request>|iterable<array-key, Request> $requests
     * @param null|callable(Response, array-key): void $responseHandler
     * @param null|callable(Throwable, array-key): void $exceptionHandler
     */
    public function pool(
        iterable|callable $requests = [],
        int $concurrency = 5,
        ?callable $responseHandler = null,
        ?callable $exceptionHandler = null,
    ): Pool {
        return new Pool($this, $requests, $concurrency, $responseHandler, $exceptionHandler);
    }

    /**
     * Resolve the HTTP connection used by this connector.
     */
    public function resolveHttpConnection(): ?string
    {
        return null;
    }

    /**
     * Resolve whether requests may replace this connector's base URL.
     */
    public function allowsBaseUrlOverride(): bool
    {
        return false;
    }

    /**
     * Get the default headers.
     *
     * @return array<string, mixed>
     */
    final public function headers(): array
    {
        return $this->defaultHeaders();
    }

    /**
     * Get the default query parameters.
     *
     * @return array<string, mixed>
     */
    final public function queryParameters(): array
    {
        return $this->defaultQuery();
    }

    /**
     * Get the default request options.
     *
     * @return array<string, mixed>
     */
    final public function options(): array
    {
        return $this->defaultOptions();
    }

    /**
     * Get the default authenticator.
     */
    final public function authenticator(): ?Authenticator
    {
        return $this->defaultAuth();
    }

    /**
     * Get the default request delay in milliseconds.
     */
    final public function delayMilliseconds(): ?int
    {
        return $this->defaultDelay();
    }

    /**
     * Copy the default body repository for a pending request.
     *
     * @internal
     */
    final public function copyDefaultBodyRepository(): ?BodyRepository
    {
        $repository = $this->defaultBodyRepository();

        return $repository === null ? null : clone $repository;
    }

    /**
     * Resolve the default headers.
     *
     * @return array<string, mixed>
     */
    protected function defaultHeaders(): array
    {
        return [];
    }

    /**
     * Resolve the default query parameters.
     *
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [];
    }

    /**
     * Resolve the default request options.
     *
     * @return array<string, mixed>
     */
    protected function defaultOptions(): array
    {
        return [];
    }

    /**
     * Resolve the default authenticator.
     */
    protected function defaultAuth(): ?Authenticator
    {
        return null;
    }

    /**
     * Resolve the default request delay in milliseconds.
     */
    protected function defaultDelay(): ?int
    {
        return null;
    }

    /**
     * Resolve the default body repository.
     */
    protected function defaultBodyRepository(): ?BodyRepository
    {
        return null;
    }

    /**
     * Resolve the custom cache key.
     *
     * @internal
     */
    final public function resolveCacheKey(PendingRequest $pendingRequest): ?string
    {
        return $this->cacheKey($pendingRequest);
    }

    /**
     * Resolve the cacheable HTTP methods.
     *
     * @return list<Method>
     * @internal
     */
    final public function resolveCacheableMethods(): array
    {
        return $this->cacheableMethods();
    }

    /**
     * Determine if this connector defines rate limits.
     *
     * @internal
     */
    public function usesRateLimits(): bool
    {
        return false;
    }

    /**
     * Resolve the connector's admission policies.
     *
     * @return list<AdmissionPolicy>
     * @internal
     */
    public function resolveRateLimitPolicies(PendingRequest $pendingRequest): array
    {
        return [];
    }

    /**
     * Resolve the connector's rate limiter store.
     *
     * @internal
     */
    public function resolveRateLimitStoreName(): UnitEnum|string|null
    {
        return null;
    }

    /**
     * Determine if connector rate limits should be awaited.
     *
     * @internal
     */
    public function shouldWaitForRateLimits(): bool
    {
        return false;
    }

    /**
     * Resolve the connector's stable cooldown key.
     *
     * @internal
     */
    public function resolveRateLimitCooldownKeyFor(PendingRequest $pendingRequest): string
    {
        return static::class;
    }

    /**
     * Resolve the connector cooldown imposed by a response.
     *
     * @internal
     */
    public function resolveRateLimitCooldownFor(Response $response): ?int
    {
        return null;
    }

    /**
     * Define a custom cache key.
     */
    protected function cacheKey(PendingRequest $pendingRequest): ?string
    {
        return null;
    }

    /**
     * Define the cacheable HTTP methods.
     *
     * @return list<Method>
     */
    protected function cacheableMethods(): array
    {
        return [Method::GET, Method::HEAD, Method::OPTIONS, Method::QUERY];
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
