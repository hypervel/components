<?php

declare(strict_types=1);

namespace Hypervel\Router;

use FastRoute\DataGenerator as DataGeneratorContract;
use FastRoute\DataGenerator\GroupCountBased as DataGenerator;
use FastRoute\RouteParser as RouteParserContract;
use FastRoute\RouteParser\Std as RouterParser;
use Hyperf\HttpServer\Router\DispatcherFactory as HyperfDispatcherFactory;
use Hyperf\HttpServer\Router\RouteCollector as HyperfRouteCollector;
use Hypervel\Router\Contracts\UrlGenerator as UrlGeneratorContract;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                HyperfDispatcherFactory::class => DispatcherFactory::class,
                RouteParserContract::class => RouterParser::class,
                DataGeneratorContract::class => DataGenerator::class,
                HyperfRouteCollector::class => RouteCollector::class,
                UrlGeneratorContract::class => UrlGenerator::class,
            ],
        ];
    }
}
