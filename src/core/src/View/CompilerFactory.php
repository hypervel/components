<?php

declare(strict_types=1);

namespace Hypervel\View;

use Hypervel\Filesystem\Filesystem;
use Hyperf\ViewEngine\Blade;
use Hypervel\View\Compilers\BladeCompiler;
use Psr\Container\ContainerInterface;

class CompilerFactory
{
    public function __invoke(ContainerInterface $container)
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
