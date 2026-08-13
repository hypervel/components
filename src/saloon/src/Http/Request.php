<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http;

use Hypervel\Contracts\Container\SelfBuilding;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Traits\Auth\AuthenticatesRequests;
use Hypervel\Saloon\Traits\Body\HasBody;
use Hypervel\Saloon\Traits\Bootable;
use Hypervel\Saloon\Traits\HandlesPsrRequest;
use Hypervel\Saloon\Traits\HasDebugging;
use Hypervel\Saloon\Traits\HasMockClient;
use Hypervel\Saloon\Traits\Makeable;
use Hypervel\Saloon\Traits\ManagesExceptions;
use Hypervel\Saloon\Traits\Request\CreatesDtoFromResponse;
use Hypervel\Saloon\Traits\RequestProperties\HasRequestProperties;
use Hypervel\Saloon\Traits\Responses\HasCustomResponses;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use LogicException;
use UnitEnum;

/** @template TDto */
abstract class Request implements SelfBuilding
{
    /** @use CreatesDtoFromResponse<TDto> */
    use CreatesDtoFromResponse;

    use AuthenticatesRequests;
    use Bootable;
    use Conditionable;
    use HandlesPsrRequest;
    use HasBody;
    use HasCustomResponses;
    use HasDebugging;
    use HasMockClient;
    use HasRequestProperties;
    use Macroable;
    use Makeable;
    use ManagesExceptions;

    /**
     * The HTTP method used by the request.
     */
    protected Method $method;

    /**
     * Create a fresh request for container resolution.
     */
    public static function newInstance(): static
    {
        return new static;
    }

    /**
     * Get the HTTP method used by the request.
     */
    public function method(): Method
    {
        if (! isset($this->method)) {
            throw new LogicException('Your request is missing an HTTP method. Add a method property such as [protected Method $method = Method::GET].');
        }

        return $this->method;
    }

    /**
     * Resolve the request endpoint.
     */
    abstract public function resolveEndpoint(): string;

    /**
     * Resolve whether this request may replace the connector base URL.
     */
    public function allowsBaseUrlOverride(): ?bool
    {
        return null;
    }

    /**
     * Determine if this request defines cache controls.
     *
     * @internal
     */
    public function usesCachingControls(): bool
    {
        return false;
    }

    /**
     * Determine if caching is enabled for this request.
     *
     * @internal
     */
    public function cachingEnabled(): bool
    {
        return true;
    }

    /**
     * Determine if the matching cache entry should be invalidated.
     *
     * @internal
     */
    public function shouldInvalidateCache(): bool
    {
        return false;
    }

    /**
     * Resolve the request's custom cache key.
     *
     * @internal
     */
    public function resolveCacheKey(PendingRequest $pendingRequest): ?string
    {
        return null;
    }

    /**
     * Resolve the request's cacheable HTTP methods.
     *
     * @return null|list<Method>
     * @internal
     */
    public function resolveCacheableMethods(): ?array
    {
        return null;
    }

    /**
     * Determine if this request defines rate limits.
     *
     * @internal
     */
    public function usesRateLimits(): bool
    {
        return false;
    }

    /**
     * Resolve the request's admission policies.
     *
     * @return list<AdmissionPolicy>
     * @internal
     */
    public function resolveRateLimitPolicies(PendingRequest $pendingRequest): array
    {
        return [];
    }

    /**
     * Resolve the request's rate limiter store.
     *
     * @internal
     */
    public function resolveRateLimitStoreName(): UnitEnum|string|null
    {
        return null;
    }

    /**
     * Determine if request rate limits should be awaited.
     *
     * @internal
     */
    public function shouldWaitForRateLimits(): bool
    {
        return false;
    }

    /**
     * Resolve the request's stable cooldown key.
     *
     * @internal
     */
    public function resolveRateLimitCooldownKeyFor(PendingRequest $pendingRequest): string
    {
        return static::class;
    }

    /**
     * Resolve the request cooldown imposed by a response.
     *
     * @internal
     */
    public function resolveRateLimitCooldownFor(Response $response): ?int
    {
        return null;
    }

    /**
     * Clone the request's initialized mutable state.
     */
    public function __clone(): void
    {
        $this->headerRepository = $this->headerRepository !== null ? clone $this->headerRepository : null;
        $this->queryRepository = $this->queryRepository !== null ? clone $this->queryRepository : null;
        $this->optionRepository = $this->optionRepository !== null ? clone $this->optionRepository : null;
        $this->delayRepository = $this->delayRepository !== null ? clone $this->delayRepository : null;
        $this->middlewarePipeline = $this->middlewarePipeline !== null ? clone $this->middlewarePipeline : null;
        $this->bodyRepository = $this->bodyRepository !== null ? clone $this->bodyRepository : null;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
