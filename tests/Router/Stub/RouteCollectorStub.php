<?php

declare(strict_types=1);

namespace Hypervel\Tests\Router\Stub;

use Hyperf\HttpServer\Router\RouteCollector;

class RouteCollectorStub extends RouteCollector
{
    public function mergeOptions(array $origin, array $options): array
    {
        return parent::mergeOptions($origin, $options);
    }
}
