<?php

declare(strict_types=1);

namespace Hypervel\Router;

use FastRoute\DataGenerator as DataGeneratorContract;
use FastRoute\DataGenerator\GroupCountBased as DataGenerator;
use FastRoute\RouteParser as RouteParserContract;
use FastRoute\RouteParser\Std as RouterParser;
use Hypervel\HttpServer\Router\DispatcherFactory as HttpServerDispatcherFactory;
use Hypervel\HttpServer\Router\RouteCollector as HttpServerRouteCollector;
use Hypervel\Support\ServiceProvider;

class RouterServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(HttpServerDispatcherFactory::class, DispatcherFactory::class);

        $this->app->singleton(HttpServerRouteCollector::class, RouteCollector::class);

        $this->app->singleton(RouteParserContract::class, RouterParser::class);

        $this->app->singleton(DataGeneratorContract::class, DataGenerator::class);

        $this->app->singleton('router', fn ($app) => new Router(
            $app->make(HttpServerDispatcherFactory::class)
        ));

        $this->app->singleton('url', fn ($app) => new UrlGenerator($app));
    }
}
