<?php

declare(strict_types=1);

namespace Hypervel\Tests\Router;

use Hyperf\Di\Definition\DefinitionSource;
use Hyperf\HttpServer\Router\RouteCollector as HyperfRouteCollector;
use Hypervel\Container\Container;
use Hypervel\Context\ApplicationContext;
use Hypervel\Router\DispatcherFactory;
use Hypervel\Router\RouteCollector;
use Hypervel\Router\RouteFileCollector;
use Hypervel\Router\Router;
use Hypervel\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * @internal
 * @coversNothing
 */
class DispatcherFactoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
    }

    public function testGetRouter()
    {
        if (! defined('BASE_PATH')) {
            $this->markTestSkipped('skip it because DispatcherFactory in hyperf is dirty.');
        }

        /** @var MockInterface|RouteCollector */
        $routeCollector = Mockery::mock(RouteCollector::class);

        $getContainer = $this->getContainer([
            HyperfRouteCollector::class => fn () => $routeCollector,
            RouteFileCollector::class => fn () => new RouteFileCollector(['foo']),
        ]);

        $factory = new DispatcherFactory($getContainer);

        $this->assertEquals($routeCollector, $factory->getRouter('http'));
    }

    public function testInitConfigRoute()
    {
        if (! defined('BASE_PATH')) {
            $this->markTestSkipped('skip it because DispatcherFactory in hyperf is dirty.');
        }

        /** @var MockInterface|RouteCollector */
        $routeCollector = Mockery::mock(RouteCollector::class);
        $routeCollector->shouldReceive('get')->with('/foo', 'Handler::Foo')->once();
        $routeCollector->shouldReceive('get')->with('/bar', 'Handler::Bar')->once();

        $container = $this->getContainer([
            HyperfRouteCollector::class => fn () => $routeCollector,
            RouteFileCollector::class => fn () => new RouteFileCollector([
                __DIR__ . '/routes/foo.php',
                __DIR__ . '/routes/bar.php',
            ]),
        ]);

        $dispatcherFactory = new DispatcherFactory($container);
        $container->define(Router::class, fn () => new Router($dispatcherFactory));

        $dispatcherFactory->initRoutes('http');
    }

    /**
     * Test that routes added to RouteFileCollector AFTER DispatcherFactory
     * construction are still loaded when initRoutes() is called.
     *
     * This simulates the loadRoutesFrom() pattern where service providers
     * add route files during boot(), after DispatcherFactory may have been
     * constructed.
     */
    public function testRoutesAddedAfterConstructionAreLoaded()
    {
        if (! defined('BASE_PATH')) {
            $this->markTestSkipped('skip it because DispatcherFactory in hyperf is dirty.');
        }

        /** @var MockInterface|RouteCollector */
        $routeCollector = Mockery::mock(RouteCollector::class);

        // Initial route from foo.php
        $routeCollector->shouldReceive('get')->with('/foo', 'Handler::Foo')->once();

        // Late-added route from late.php - this MUST be loaded
        $routeCollector->shouldReceive('get')->with('/late', 'Handler::Late')->once();

        // Create RouteFileCollector with only the initial route
        $routeFileCollector = new RouteFileCollector([
            __DIR__ . '/routes/foo.php',
        ]);

        $container = $this->getContainer([
            HyperfRouteCollector::class => fn () => $routeCollector,
            RouteFileCollector::class => fn () => $routeFileCollector,
        ]);

        // Create DispatcherFactory - this captures route files in constructor
        $dispatcherFactory = new DispatcherFactory($container);
        $container->define(Router::class, fn () => new Router($dispatcherFactory));

        // Simulate service provider adding routes AFTER DispatcherFactory construction
        // This is what loadRoutesFrom() does
        $routeFileCollector->addRouteFile(__DIR__ . '/routes/late.php');

        // Now trigger route loading - late.php should be included
        $dispatcherFactory->getRouter('http');
    }

    private function getContainer(array $bindings = []): Container
    {
        $container = new Container(
            new DefinitionSource($bindings)
        );

        ApplicationContext::setContainer($container);

        return $container;
    }
}
