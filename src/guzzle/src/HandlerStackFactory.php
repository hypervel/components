<?php

declare(strict_types=1);

namespace Hypervel\Guzzle;

use GuzzleHttp\HandlerStack;
use Hypervel\Coroutine\Coroutine;

use function app;

class HandlerStackFactory
{
    /**
     * The default pool options.
     */
    protected array $option = [
        'min_connections' => 1,
        'max_connections' => 30,
        'wait_timeout' => 3.0,
        'max_idle_time' => 60,
    ];

    /**
     * The default middlewares.
     */
    protected array $middlewares = [
        'retry' => [RetryMiddleware::class, [1, 10]],
    ];

    /**
     * Create a new handler stack.
     */
    public function create(array $option = [], array $middlewares = []): HandlerStack
    {
        $handler = null;
        $option = array_merge($this->option, $option);
        $middlewares = array_merge($this->middlewares, $middlewares);

        if (Coroutine::inCoroutine()) {
            $handler = $this->getHandler($option);
        }

        $stack = HandlerStack::create($handler);

        foreach ($middlewares as $key => $middleware) {
            if (is_array($middleware)) {
                [$class, $arguments] = $middleware;
                $middleware = new $class(...$arguments);
            }

            if ($middleware instanceof MiddlewareInterface) {
                $stack->push($middleware->getMiddleware(), $key);
            }
        }

        return $stack;
    }

    /**
     * Get the appropriate handler based on the environment.
     */
    protected function getHandler(array $option): PoolHandler
    {
        return app(PoolHandler::class, [
            'option' => $option,
        ]);
    }
}
