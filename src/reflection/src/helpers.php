<?php

declare(strict_types=1);

use Hypervel\Reflection\Traits\ReflectsClosures;

if (! function_exists('lazy')) {
    /**
     * Create a lazy instance.
     *
     * @template TValue of object
     *
     * @param class-string<TValue>|(Closure(TValue): mixed) $class
     * @param (Closure(TValue): mixed)|int $callback
     * @param int $options
     * @param array<string, mixed> $eager
     * @return TValue
     */
    function lazy(string|Closure $class, Closure|int $callback = 0, int $options = 0, array $eager = []): object
    {
        static $closureReflector;

        $closureReflector ??= new class
        {
            use ReflectsClosures;

            public function typeFromParameter(Closure $callback): string
            {
                return $this->firstClosureParameterType($callback);
            }
        };

        [$class, $callback, $options] = is_string($class)
            ? [$class, $callback, $options]
            : [$closureReflector->typeFromParameter($class), $class, $callback ?: $options];

        $reflectionClass = new ReflectionClass($class);

        $instance = $reflectionClass->newLazyGhost(function ($instance) use ($callback) {
            $result = $callback($instance);

            if (is_array($result)) {
                $instance->__construct(...$result);
            }
        }, $options);

        foreach ($eager as $property => $value) {
            $reflectionClass->getProperty($property)->setRawValueWithoutLazyInitialization($instance, $value);
        }

        return $instance;
    }
}

if (! function_exists('proxy')) {
    /**
     * Create a lazy proxy instance.
     *
     * @template TValue of object
     *
     * @param class-string<TValue>|(Closure(TValue): TValue) $class
     * @param (Closure(TValue): TValue)|int $callback
     * @param int $options
     * @param array<string, mixed> $eager
     * @return TValue
     */
    function proxy(string|Closure $class, Closure|int $callback = 0, int $options = 0, array $eager = []): object
    {
        static $closureReflector;

        $closureReflector ??= new class
        {
            use ReflectsClosures;

            public function get(Closure $callback): string
            {
                return $this->closureReturnTypes($callback)[0] ?? $this->firstClosureParameterType($callback);
            }
        };

        [$class, $callback, $options] = is_string($class)
            ? [$class, $callback, $options]
            : [$closureReflector->get($class), $class, $callback ?: $options];

        $reflectionClass = new ReflectionClass($class);

        $proxy = $reflectionClass->newLazyProxy(function () use ($callback, $eager, &$proxy) {
            $instance = $callback($proxy, $eager);

            return $instance;
        }, $options);

        foreach ($eager as $property => $value) {
            $reflectionClass->getProperty($property)->setRawValueWithoutLazyInitialization($proxy, $value);
        }

        return $proxy;
    }
}
