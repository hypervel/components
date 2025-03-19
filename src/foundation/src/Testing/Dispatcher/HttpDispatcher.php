<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Dispatcher;

use Hyperf\Context\ApplicationContext;
use Hyperf\Dispatcher\HttpDispatcher as HyperfHttpDispatcher;
use Psr\Http\Message\ResponseInterface;

class HttpDispatcher extends HyperfHttpDispatcher
{
    public function dispatch(...$params): ResponseInterface
    {
        [$request, $middlewares, $coreHandler] = $params;

        // remove middleware if disabled in testing
        $container = ApplicationContext::getContainer();
        if ($container->has('middleware.disable')
            && $container->get('middleware.disable')) {
            $middlewares = [];
        }

        return parent::dispatch($request, $middlewares, $coreHandler);
    }
}
