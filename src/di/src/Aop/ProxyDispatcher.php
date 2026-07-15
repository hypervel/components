<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Support\SplPriorityQueue;
use ValueError;

class ProxyDispatcher
{
    /**
     * Dispatch an intercepted method through its aspect pipeline.
     */
    public static function dispatch(
        string $className,
        string $methodName,
        array $arguments,
        Closure $originalMethod
    ): mixed {
        $proceedingJoinPoint = new ProceedingJoinPoint(
            $originalMethod,
            $className,
            $methodName,
            $arguments
        );

        $aspects = self::resolveAspects($className, $methodName);

        if ($aspects === []) {
            return $proceedingJoinPoint->processOriginalMethod();
        }

        return (new Pipeline(Container::getInstance()))
            ->via('process')
            ->through($aspects)
            ->send($proceedingJoinPoint)
            ->then(static fn (ProceedingJoinPoint $point) => $point->processOriginalMethod());
    }

    /**
     * Reconstruct the arguments visible to the intercepted method.
     */
    public static function resolveArguments(int $count, array $fixed, array $variadic = []): array
    {
        $fixedCount = min($count, count($fixed));

        return array_merge(
            array_slice($fixed, 0, $fixedCount),
            array_slice($variadic, 0, max(0, $count - $fixedCount))
        );
    }

    /**
     * Resolve one argument visible to the intercepted method.
     */
    public static function resolveArgument(int $count, array $fixed, array $variadic, int $position): mixed
    {
        if ($position < 0) {
            throw new ValueError('func_get_arg(): Argument #1 ($position) must be greater than or equal to 0');
        }

        if ($position >= $count) {
            throw new ValueError(
                'func_get_arg(): Argument #1 ($position) must be less than the number of the arguments passed '
                . 'to the currently executed function'
            );
        }

        return static::resolveArguments($count, $fixed, $variadic)[$position];
    }

    /**
     * Capture the positional variadic arguments visible to the original call.
     */
    public static function captureVariadicArguments(array &$arguments, int $limit, bool $byReference): array
    {
        $captured = [];

        foreach ($arguments as $key => &$argument) {
            if (! is_int($key)) {
                continue;
            }

            if (count($captured) >= $limit) {
                break;
            }

            if ($byReference) {
                $captured[] = &$argument;
            } else {
                $captured[] = $argument;
            }
        }

        unset($argument);

        return $captured;
    }

    /**
     * Resolve and cache the aspects for a class method.
     *
     * @return array<int, string>
     */
    private static function resolveAspects(string $className, string $methodName): array
    {
        if (AspectManager::has($className, $methodName)) {
            return AspectManager::get($className, $methodName);
        }

        $matchedAspects = [];

        foreach (AspectCollector::getClassRules() as $aspect => $rules) {
            foreach ($rules as $rule) {
                if (Aspect::isMatch($className, $methodName, $rule)) {
                    $matchedAspects[] = $aspect;
                    break;
                }
            }
        }

        $queue = new SplPriorityQueue;

        foreach (array_unique($matchedAspects) as $aspect) {
            $queue->insert($aspect, AspectCollector::getPriority($aspect));
        }

        $resolvedAspects = [];

        while ($queue->valid()) {
            $resolvedAspects[] = $queue->current();
            $queue->next();
        }

        // Publish only the complete immutable list so another coroutine can never
        // observe a cache entry while it is still being assembled.
        AspectManager::set($className, $methodName, $resolvedAspects);

        return $resolvedAspects;
    }
}
