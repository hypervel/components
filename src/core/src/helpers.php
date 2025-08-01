<?php

declare(strict_types=1);

if (! function_exists('context')) {
    /**
     * Get / set the specified context value in the current coroutine.
     *
     * @param  array|string|null  $key
     * @param  mixed  $default
     * @param  int|null  $coroutineId
     * @return mixed
     */
    function context(array|string|null $key = null, mixed $default = null, ?int $coroutineId = null): mixed
    {
        /**
         * 
         * Just want to make sure that the context is an object that has all methods of \Hypervel\Context\Context class.
         * Could have used \Hyperf\Engine\Coroutine::getContextFor($coroutineId) instead but prefer to rely on hypervel's Context class.
         * This way, we can ensure that the context is always an instance of Hypervel\Context\Context
         *  @see \Hypervel\Context\Context::get()
         * 
         * @var \Hypervel\Context\Context|array $context
         */
        $context = new class extends \ArrayObject {
            public function __call(string $method, array $arguments): mixed
            {
                return \Hypervel\Context\Context::{$method}(...$arguments);
            }
        };
        return match (true) {
            is_null($key) => $context,
            is_array($key) => tap($context, function () use ($key, $coroutineId) {
                foreach ($key as $k => $v) {
                    \Hypervel\Context\Context::set($k, $v, $coroutineId);
                }
            }),
            default => \Hypervel\Context\Context::get($key, $default, $coroutineId),
        };
    }
}
