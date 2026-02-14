<?php

declare(strict_types=1);

namespace Hypervel\Dispatcher;

use Hypervel\Contracts\Container\Container;
use Hypervel\Dispatcher\Exceptions\InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

abstract class AbstractRequestHandler
{
    protected int $offset = 0;

    /**
     * Create a new request handler instance.
     */
    public function __construct(
        protected array $middlewares,
        protected MiddlewareInterface $coreHandler,
        protected Container $container,
    ) {
        $this->middlewares = array_values($this->middlewares);
    }

    /**
     * Handle the current request through the middleware stack.
     */
    protected function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        if (! isset($this->middlewares[$this->offset])) {
            $handler = $this->coreHandler;
        } else {
            $handler = $this->middlewares[$this->offset];
            is_string($handler) && $handler = $this->container->get($handler);
        }
        if (! $handler || ! method_exists($handler, 'process')) {
            throw new InvalidArgumentException('Invalid middleware, it has to provide a process() method.');
        }

        return $handler->process($request, $this->next());
    }

    /**
     * Get the next request handler in the middleware stack.
     */
    protected function next(): static
    {
        ++$this->offset;

        return $this;
    }
}
