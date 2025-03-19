<?php

declare(strict_types=1);

namespace Hypervel\Dispatcher;

use Closure;
use Hyperf\HttpServer\Contract\CoreMiddlewareInterface;
use Hyperf\Pipeline\Pipeline as BasePipeline;
use Psr\Http\Server\MiddlewareInterface;

class Pipeline extends BasePipeline
{
    protected array $adaptedMiddleware = [];

    protected array $coreMiddleware = [];

    /**
     * Get a Closure that represents a slice of the application onion.
     */
    protected function carry(): Closure
    {
        return function ($stack, $pipe) {
            return function ($passable) use ($stack, $pipe) {
                if (is_callable($pipe)) {
                    // If the pipe is an instance of a Closure, we will just call it directly, but
                    // otherwise we'll resolve the pipes out of the container and call it with
                    // the appropriate method and arguments, returning the results back out.
                    return $pipe($passable, $stack);
                }
                if ($pipe instanceof ParsedMiddleware || is_string($pipe)) {
                    if ($pipe instanceof ParsedMiddleware) {
                        $name = $pipe->getName();
                        $parameters = $pipe->getParameters();
                    } else {
                        [$name, $parameters] = $this->parsePipeString($pipe);
                    }
                    // If the pipe is a string we will parse the string and resolve the class out
                    // of the dependency injection container. We can then build a callable and
                    // execute the pipe function giving in the parameters that are required.
                    $pipe = $this->getPipeInstance($name);

                    $parameters = array_merge([$passable, $stack], $parameters);
                } else {
                    // Convert the core middleware to adapted core middleware
                    if ($pipe instanceof CoreMiddlewareInterface) {
                        $pipe = $this->getAdaptedCoreMiddleware($pipe);
                    }
                    // If the pipe is already an object we'll just make a callable and pass it to
                    // the pipe as-is. There is no need to do any extra parsing and formatting
                    // since the object we're given was already a fully instantiated object.
                    $parameters = [$passable, $stack];
                }

                $carry = method_exists($pipe, $this->method) ? $pipe->{$this->method}(...$parameters) : $pipe(...$parameters);

                return $this->handleCarry($carry);
            };
        };
    }

    protected function getAdaptedCoreMiddleware(CoreMiddlewareInterface $coreMiddleware): Psr15AdapterMiddleware
    {
        $coreMiddlewareName = get_class($coreMiddleware);
        if ($cachedCoreMiddleware = ($this->coreMiddleware[$coreMiddlewareName] ?? null)) {
            return $cachedCoreMiddleware;
        }

        return $this->coreMiddleware[$coreMiddlewareName] = new Psr15AdapterMiddleware($coreMiddleware, true);
    }

    protected function getPipeInstance(string $name): object
    {
        $pipe = $this->container->get($name);

        if ($pipe instanceof MiddlewareInterface) {
            // cache the adapted middleware instance
            if ($middleware = ($this->adaptedMiddleware[$name] ?? null)) {
                return $middleware;
            }

            return $this->adaptedMiddleware[$name] = new Psr15AdapterMiddleware($pipe);
        }

        return $pipe;
    }
}
