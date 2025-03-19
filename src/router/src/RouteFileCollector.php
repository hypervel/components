<?php

declare(strict_types=1);

namespace Hypervel\Router;

class RouteFileCollector
{
    protected array $routeFiles = [];

    public function __construct(array $routeFiles = [])
    {
        $this->routeFiles = $routeFiles
            ?: [BASE_PATH . '/config/routes.php'];
    }

    public function addRouteFile(string $routeFile): void
    {
        $this->addRouteFiles([$routeFile]);
    }

    public function addRouteFiles(array $routeFiles): void
    {
        $this->routeFiles = array_unique(array_merge($this->routeFiles, $routeFiles));
    }

    public function setRouteFiles(array $routeFiles): void
    {
        $this->routeFiles = $routeFiles;
    }

    public function getRouteFiles(): array
    {
        return $this->routeFiles;
    }
}
