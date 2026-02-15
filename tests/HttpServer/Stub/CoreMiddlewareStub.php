<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer\Stub;

use Hypervel\HttpMessage\Server\Response;
use Hypervel\HttpServer\CoreMiddleware;
use Hypervel\Contracts\Http\ResponsePlusInterface;

class CoreMiddlewareStub extends CoreMiddleware
{
    public function parseMethodParameters(string $controller, string $action, array $arguments): array
    {
        return parent::parseMethodParameters($controller, $action, $arguments);
    }

    protected function response(): ResponsePlusInterface
    {
        return new Response();
    }
}
