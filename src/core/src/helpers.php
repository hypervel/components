<?php

declare(strict_types=1);

use Hypervel\Context\ApplicationContext;
use Hypervel\Context\Context;

if (! function_exists('context')) {
    /**
     * Get / set the specified context value in the current coroutine.
     */
    function context(array|string|null $key = null, mixed $default = null, ?int $coroutineId = null): mixed
    {
        return match (true) {
            is_null($key) => ApplicationContext::getContainer()->get(Context::class),
            is_array($key) => Context::setMany($key, $coroutineId),
            default => Context::get($key, $default, $coroutineId),
        };
    }
}
