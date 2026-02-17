<?php

declare(strict_types=1);

namespace Hypervel\Router;

use FastRoute\DataGenerator as DataGeneratorContract;
use FastRoute\DataGenerator\GroupCountBased as DataGenerator;
use FastRoute\RouteParser as RouteParserContract;
use FastRoute\RouteParser\Std as RouterParser;
use Hypervel\Contracts\Router\UrlGenerator as UrlGeneratorContract;
use Hypervel\HttpServer\Router\DispatcherFactory as HttpServerDispatcherFactory;
use Hypervel\HttpServer\Router\RouteCollector as HttpServerRouteCollector;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                HttpServerDispatcherFactory::class => DispatcherFactory::class,
                RouteParserContract::class => RouterParser::class,
                DataGeneratorContract::class => DataGenerator::class,
                HttpServerRouteCollector::class => RouteCollector::class,
                UrlGeneratorContract::class => UrlGenerator::class,
            ],
        ];
    }
}
