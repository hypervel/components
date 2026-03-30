<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use Hypervel\Context\CoroutineContext;

trait StaticInstance
{
    /**
     * Get or create a coroutine-scoped singleton instance.
     */
    public static function instance(array $params = [], bool $refresh = false, string $suffix = ''): static
    {
        $key = static::class . $suffix;
        $instance = null;

        if (CoroutineContext::has($key)) {
            $instance = CoroutineContext::get($key);
        }

        if ($refresh || ! $instance instanceof static) {
            $instance = new static(...$params);
            CoroutineContext::set($key, $instance);
        }

        return $instance;
    }
}
