<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Closure;
use Hypervel\Di\Exceptions\Exception;
use Hypervel\Di\ReflectionManager;
use ReflectionFunction;
use ReflectionMethod;

class ProceedingJoinPoint
{
    public mixed $result;

    public ?Closure $pipe = null;

    public function __construct(
        public Closure $originalMethod,
        public string $className,
        public string $methodName,
        public array $arguments
    ) {
    }

    /**
     * Delegate to the next aspect in the pipeline.
     */
    public function process(): mixed
    {
        $closure = $this->pipe;
        if (! $closure instanceof Closure) {
            throw new Exception('The pipe is not instanceof \Closure');
        }

        return $closure($this);
    }

    /**
     * Process the original method, bypassing remaining aspects.
     */
    public function processOriginalMethod(): mixed
    {
        $this->pipe = null;
        $closure = $this->originalMethod;
        $arguments = $this->getArguments();
        return $closure(...$arguments);
    }

    /**
     * Get the ordered arguments array for the original method call.
     */
    public function getArguments(): array
    {
        $result = [];
        foreach ($this->arguments['order'] ?? [] as $order) {
            $result[] = $this->arguments['keys'][$order];
        }

        // Variable arguments are always placed at the end.
        if (isset($this->arguments['variadic'], $order) && $order === $this->arguments['variadic']) {
            $variadic = array_pop($result);
            $result = array_merge($result, $variadic);
        }
        return $result;
    }

    /**
     * Get the reflection method for the intercepted method.
     */
    public function getReflectMethod(): ReflectionMethod
    {
        return ReflectionManager::reflectMethod(
            $this->className,
            $this->methodName
        );
    }

    /**
     * Get the object instance the original method is bound to.
     */
    public function getInstance(): ?object
    {
        $ref = new ReflectionFunction($this->originalMethod);

        return $ref->getClosureThis();
    }
}
