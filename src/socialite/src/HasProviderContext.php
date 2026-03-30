<?php

declare(strict_types=1);

namespace Hypervel\Socialite;

use Hypervel\Context\CoroutineContext;

trait HasProviderContext
{
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
        return '__socialite.providers.' . static::class . '.' . $key;
    }
}
