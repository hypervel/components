<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Support\SplPriorityQueue;

trait ProxyTrait
{
    /**
     * Entry point for proxied method calls, inserted by ProxyCallVisitor.
     */
    protected static function __proxyCall(
        string $className,
        string $method,
        array $arguments,
        Closure $closure
    ): mixed {
        $proceedingJoinPoint = new ProceedingJoinPoint($closure, $className, $method, $arguments);
        $result = self::handleAround($proceedingJoinPoint);
        unset($proceedingJoinPoint);
        return $result;
    }

    /**
     * Resolve and execute the aspect pipeline for the given join point.
     */
    protected static function handleAround(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        $className = $proceedingJoinPoint->className;
        $methodName = $proceedingJoinPoint->methodName;
        if (! AspectManager::has($className, $methodName)) {
            AspectManager::set($className, $methodName, []);
            $aspects = array_unique(static::getClassesAspects($className, $methodName));
            $queue = new SplPriorityQueue;
            foreach ($aspects as $aspect) {
                $queue->insert($aspect, AspectCollector::getPriority($aspect));
            }
            while ($queue->valid()) {
                AspectManager::insert($className, $methodName, $queue->current());
                $queue->next();
            }

            unset($aspects, $queue);
        }

        if (empty(AspectManager::get($className, $methodName))) {
            return $proceedingJoinPoint->processOriginalMethod();
        }

        return static::makePipeline()->via('process')
            ->through(AspectManager::get($className, $methodName))
            ->send($proceedingJoinPoint)
            ->then(function (ProceedingJoinPoint $proceedingJoinPoint) {
                return $proceedingJoinPoint->processOriginalMethod();
            });
    }

    /**
     * Create a new AOP pipeline instance.
     *
     * Must be `new` — Pipeline is mutable and auto-singletoning it would
     * share state across concurrent coroutines.
     */
    protected static function makePipeline(): Pipeline
    {
        return new Pipeline(Container::getInstance());
    }

    /**
     * Get the aspects that target the given class method via class rules.
     */
    protected static function getClassesAspects(string $className, string $method): array
    {
        $aspects = AspectCollector::get('classes', []);
        $matchedAspects = [];
        foreach ($aspects as $aspect => $rules) {
            foreach ($rules as $rule) {
                if (Aspect::isMatch($className, $method, $rule)) {
                    $matchedAspects[] = $aspect;
                    break;
                }
            }
        }
        return $matchedAspects;
    }
}
