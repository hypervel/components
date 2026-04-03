<?php

declare(strict_types=1);

namespace Hypervel\HttpServer\Router;

use FastRoute\DataGenerator\GroupCountBased as DataGenerator;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased;
use FastRoute\RouteParser\Std;

class DispatcherFactory
{
    protected array $routes = [BASE_PATH . '/config/routes.php'];

    /**
     * @var RouteCollector[]
     */
    protected array $routers = [];

    /**
     * @var Dispatcher[]
     */
    protected array $dispatchers = [];

    public function __construct()
    {
        $this->initConfigRoute();
    }

    /**
     * Get the FastRoute dispatcher for the given server.
     */
    public function getDispatcher(string $serverName): Dispatcher
    {
        if (isset($this->dispatchers[$serverName])) {
            return $this->dispatchers[$serverName];
        }

        $router = $this->getRouter($serverName);
        return $this->dispatchers[$serverName] = new GroupCountBased($router->getData());
    }

    /**
     * Initialize routes from config files.
     */
    public function initConfigRoute(): void
    {
        Router::init($this);
        foreach ($this->routes as $route) {
            if (file_exists($route)) {
                require $route;
            }
        }
    }

    /**
     * Get the route collector for the given server.
     */
    public function getRouter(string $serverName): RouteCollector
    {
        if (isset($this->routers[$serverName])) {
            return $this->routers[$serverName];
        }

        $parser = new Std();
        $generator = new DataGenerator();
        return $this->routers[$serverName] = new RouteCollector($parser, $generator, $serverName);
    }
}
