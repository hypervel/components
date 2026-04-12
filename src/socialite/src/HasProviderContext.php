<?php

declare(strict_types=1);

namespace Hypervel\Socialite;

use Hypervel\Context\CoroutineContext;

trait HasProviderContext
{
    /**
     * Monotonic counter for generating unique context namespaces.
     *
     * Each class using this trait gets its own counter (PHP trait static
     * property semantics). Combined with the class name in the namespace,
     * this guarantees globally unique context keys per provider instance.
     */
    protected static int $nextContextInstanceId = 0;

    /**
     * The unique context namespace for this provider instance.
     *
     * Lazily initialized on first access. Persists for the instance's lifetime,
     * ensuring stable context keys even across coroutines.
     */
    protected ?string $contextNamespace = null;

    public function getContext(string $key, mixed $default = null): mixed
    {
        return CoroutineContext::get($this->getContextKey($key), $default);
    }

    public function setContext(string $key, mixed $value): mixed
    {
        return CoroutineContext::set($this->getContextKey($key), $value);
    }

    public function getOrSetContext(string $key, mixed $value): mixed
    {
        return CoroutineContext::getOrSet($this->getContextKey($key), $value);
    }

    protected function getContextKey(string $key): string
    {
        $namespace = $this->contextNamespace
            ??= '__socialite.providers.' . static::class . '.' . (++static::$nextContextInstanceId);

        return $namespace . '.' . $key;
    }
}
