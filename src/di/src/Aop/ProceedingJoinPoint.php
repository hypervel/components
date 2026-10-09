<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Closure;
use Hypervel\Di\Exceptions\Exception;
use Hypervel\Support\ClassMetadataCache;
use ReflectionMethod;

class ProceedingJoinPoint
{
    public mixed $result;

    public ?Closure $pipe = null;

    /**
     * Create an invocation of an intercepted method.
     */
    public function __construct(
        public Closure $originalMethod,
        public string $className,
        public string $methodName,
        public array $arguments,
        protected readonly ?object $instance
    ) {
    }

    /**
     * Delegate to the next aspect in the chain.
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
            if (($this->arguments['variadic'] ?? '') !== $order) {
                $result[] = &$this->arguments['keys'][$order];

                continue;
            }

            foreach ($this->arguments['keys'][$order] as $key => &$value) {
                if (is_string($key)) {
                    $result[$key] = &$value;
                } else {
                    $result[] = &$value;
                }
            }

            unset($value);
        }

        return $result;
    }

    /**
     * Get the reflection method for the intercepted method.
     */
    public function getReflectMethod(): ReflectionMethod
    {
        return ClassMetadataCache::reflectMethod(
            $this->className,
            $this->methodName
        );
    }

    /**
     * Get the object instance whose method was intercepted.
     */
    public function getInstance(): ?object
    {
        return $this->instance;
    }
}
