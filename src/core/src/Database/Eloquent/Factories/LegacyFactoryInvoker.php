<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Faker\Factory as FakerFactory;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

class LegacyFactoryInvoker
{
    public function __invoke(ContainerInterface $container)
    {
        $config = $container->get(ConfigInterface::class);

        $factory = new LegacyFactory(
            FakerFactory::create($config->get('app.faker_locale', 'en_US'))
        );

        if (is_dir($path = database_path('factories') ?: '')) {
            $factory->load($path);
        }

        return $factory;
    }
}
