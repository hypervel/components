<?php

declare(strict_types=1);

if (! function_exists('context')) {
    /**
     * Get / set the specified context value in the current coroutine.
     *
     * @param  array|string|null  $key
     * @param  mixed  $default
     * @param int|null $coroutineId
     * @return mixed
     */
    function context($key = null, $default = null, ?int $coroutineId = null)
    {
        return match (true) {
            is_null($key) => \Hypervel\Context\Context::class,
            is_array($key) => context_set_multiple($key, $coroutineId),
            default => \Hypervel\Context\Context::get($key, $default, $coroutineId),
        };
    }
}

if (! function_exists('context_set_multiple')) {
    /**
     * Set multiple context values at once.
     *
     * @param  array  $values
     * @param int|null $coroutineId
     * @return string
     */
    function context_set_multiple(array $values, ?int $coroutineId = null): string
    {
        foreach ($values as $key => $value) {
            \Hypervel\Context\Context::set($key, $value, $coroutineId);
        }
        
        return \Hypervel\Context\Context::class;
    }
}
