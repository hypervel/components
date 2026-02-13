<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Dispatcher;

use Hyperf\Dispatcher\HttpDispatcher as HyperfHttpDispatcher;
use Hypervel\Container\Container;
use Psr\Http\Message\ResponseInterface;

class HttpDispatcher extends HyperfHttpDispatcher
{
    public function dispatch(...$params): ResponseInterface
    {
        [$request, $middlewares, $coreHandler] = $params;

        // remove middleware if disabled in testing
        $container = Container::getInstance();
        if ($container->has('middleware.disable')
            && $container->make('middleware.disable')) {
            $middlewares = [];
        }

        return parent::dispatch($request, $middlewares, $coreHandler);
    }
}
