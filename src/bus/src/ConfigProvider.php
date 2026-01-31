<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Contracts\Bus\BatchRepository;
use Hypervel\Contracts\Bus\Dispatcher as DispatcherContract;
use Psr\Container\ContainerInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                DispatcherContract::class => DispatcherFactory::class,
                BatchRepository::class => fn (ContainerInterface $container) => $container->get(DatabaseBatchRepository::class),
                DatabaseBatchRepository::class => DatabaseBatchRepositoryFactory::class,
            ],
        ];
    }
}
