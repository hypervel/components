<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Concerns;

use Hypervel\Container\DefinitionSource;
use Hypervel\Foundation\Application;

trait HasMockedApplication
{
    protected function getApplication(array $definitionSources = [], string $basePath = 'base_path'): Application
    {
        return new Application(
            new DefinitionSource($definitionSources),
            $basePath
        );
    }
}
