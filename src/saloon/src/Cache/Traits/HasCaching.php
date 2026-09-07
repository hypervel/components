<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Cache\Traits;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\PendingRequest;
use UnitEnum;

trait HasCaching
{
    /**
     * Whether caching is enabled for this request.
     */
    protected bool $cachingEnabled = true;

    /**
     * Whether the matching cache entry should be invalidated.
     */
    protected bool $invalidateCache = false;

    /**
     * Enable caching for this request.
     *
     * @return $this
     */
    public function enableCaching(): static
    {
        $this->cachingEnabled = true;

        return $this;
    }

    /**
     * Disable caching for this request.
     *
     * @return $this
     */
    public function disableCaching(): static
    {
        $this->cachingEnabled = false;

        return $this;
    }

    /**
     * Invalidate and refresh the matching cache entry.
     *
     * @return $this
     */
    public function invalidateCache(): static
    {
        $this->invalidateCache = true;

        return $this;
    }

    /**
     * Get the cache store name.
     */
    public function cacheStore(): UnitEnum|string|null
    {
        return null;
    }

    /**
     * Determine if this request defines cache controls.
     *
     * @internal
     */
    final public function usesCachingControls(): bool
    {
        return true;
    }

    /**
     * Determine if request caching is enabled.
     *
     * @internal
     */
    final public function cachingEnabled(): bool
    {
        return $this->cachingEnabled;
    }

    /**
     * Determine if the matching entry should be invalidated.
     *
     * @internal
     */
    final public function shouldInvalidateCache(): bool
    {
        return $this->invalidateCache;
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
     * @return null|list<Method>
     * @internal
     */
    final public function resolveCacheableMethods(): ?array
    {
        return $this->cacheableMethods();
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
     * @return null|list<Method>
     */
    protected function cacheableMethods(): ?array
    {
        return null;
    }
}
