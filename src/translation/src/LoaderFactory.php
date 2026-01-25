<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Translation\Loader as LoaderContract;
use Psr\Container\ContainerInterface;

class LoaderFactory
{
    public function __invoke(ContainerInterface $container): LoaderContract
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
