<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Concerns;

use Hypervel\Foundation\Application;

trait HasMockedApplication
{
    protected function getApplication(array $dependencies = [], string $basePath = 'base_path'): Application
    {
        $app = new Application($basePath);

        foreach ($dependencies as $abstract => $concrete) {
            $app->singleton($abstract, $concrete);
        }

        return $app;
    }
}
