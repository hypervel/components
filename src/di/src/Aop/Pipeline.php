<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Closure;
use Hypervel\Di\Exceptions\InvalidDefinitionException;

class Pipeline extends \Hypervel\Pipeline\Pipeline
{
    /**
     * Get the final piece of the Closure onion.
     *
     * Uses a static closure to avoid capturing $this, which would create
     * a circular reference: Pipeline→passable→ProceedingJoinPoint→pipe→
     * destination closure→$this→Pipeline. The parent's version captures
     * $this for handleException(), but AOP doesn't need exception-to-response
     * conversion — exceptions propagate naturally through the aspect chain.
     */
    protected function prepareDestination(Closure $destination): Closure
    {
        return static function ($passable) use ($destination) {
            return $destination($passable);
        };
    }

    /**
     * Get the Closure that represents an AOP pipeline slice.
     */
    protected function carry(): Closure
    {
        return function ($stack, $pipe) {
            return function ($passable) use ($stack, $pipe) {
                if (is_string($pipe) && class_exists($pipe)) {
                    $pipe = $this->container->make($pipe);
                }
                if (! $passable instanceof ProceedingJoinPoint) {
                    throw new InvalidDefinitionException('$passable must be a ProceedingJoinPoint object.');
                }
                $passable->pipe = $stack;
                return method_exists($pipe, $this->method) ? $pipe->{$this->method}($passable) : $pipe($passable);
            };
        };
    }
}
