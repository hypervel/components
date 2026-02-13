<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Translation\Loader as LoaderContract;
use Hypervel\Filesystem\Filesystem;

class LoaderFactory
{
    public function __invoke(Container $container): LoaderContract
    {
        $langPath = $container instanceof ApplicationContract
            ? $container->langPath()
            : BASE_PATH . DIRECTORY_SEPARATOR . 'lang';

        return new FileLoader(
            $container->make(Filesystem::class),
            [
                dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lang',
                $langPath,
            ]
        );
    }
}
