<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Translation\Loader as LoaderContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Contracts\Container\Container;

class LoaderFactory
{
    public function __invoke(Container $container): LoaderContract
    {
        $langPath = $container instanceof ApplicationContract
            ? $container->langPath()
            : BASE_PATH . DIRECTORY_SEPARATOR . 'lang';

        return new FileLoader(
            $container->get(Filesystem::class),
            [
                dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lang',
                $langPath,
            ]
        );
    }
}
