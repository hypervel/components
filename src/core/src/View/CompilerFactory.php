<?php

declare(strict_types=1);

namespace Hypervel\View;

use Hyperf\ViewEngine\Blade;
use Hypervel\Filesystem\Filesystem;
use Hypervel\View\Compilers\BladeCompiler;
use Hypervel\Contracts\Container\Container;

class CompilerFactory
{
    public function __invoke(Container $container)
    {
        $blade = new BladeCompiler(
            $container->get(Filesystem::class),
            Blade::config('config.cache_path')
        );

        // register view components
        foreach ((array) Blade::config('components', []) as $alias => $class) {
            $blade->component($class, $alias);
        }

        $blade->setComponentAutoload((array) Blade::config('autoload', ['classes' => [], 'components' => []]));

        return $blade;
    }
}
