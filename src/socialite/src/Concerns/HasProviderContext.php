<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Concerns;

use Hypervel\Context\CoroutineContext;

trait HasProviderContext
{
    /**
     * The next provider context namespace.
     *
     * Hypervel\Socialite\AbstractProvider must remain this concern's only root user so
     * every provider instance shares one sequence for the fixed key prefix.
     */
    protected static int $nextContextNamespace = 0;

    /**
     * The unique context namespace for this provider instance.
     *
     * Lazily initialized on first access. Persists for the instance's lifetime,
     * ensuring stable context keys even across coroutines.
     */
    protected ?string $contextNamespace = null;

    /**
     * Get a value from the provider context.
     */
    protected function getContext(string $key, mixed $default = null): mixed
    {
        return CoroutineContext::get($this->getContextKey($key), $default);
    }

    /**
     * Set a value in the provider context.
     */
    protected function setContext(string $key, mixed $value): mixed
    {
        return CoroutineContext::set($this->getContextKey($key), $value);
    }

    /**
     * Get or set a value in the provider context.
     */
    protected function getOrSetContext(string $key, mixed $value): mixed
    {
        return CoroutineContext::getOrSet($this->getContextKey($key), $value);
    }

    /**
     * Forget a value from the provider context.
     */
    protected function forgetContext(string $key): void
    {
        CoroutineContext::forget($this->getContextKey($key));
    }

    /**
     * Get the context key for the provider.
     */
    protected function getContextKey(string $key): string
    {
        $namespace = $this->contextNamespace
            ??= '__socialite.providers.' . ++self::$nextContextNamespace;

        return $namespace . '.' . $key;
    }
}
