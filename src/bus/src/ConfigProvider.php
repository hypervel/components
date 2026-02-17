<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Contracts\Bus\BatchRepository;
use Hypervel\Contracts\Bus\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Container\Container;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                DispatcherContract::class => DispatcherFactory::class,
                BatchRepository::class => fn (Container $container) => $container->make(DatabaseBatchRepository::class),
                DatabaseBatchRepository::class => DatabaseBatchRepositoryFactory::class,
            ],
        ];
    }
}
