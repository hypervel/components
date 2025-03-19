<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Bus\Contracts\BatchRepository;
use Hypervel\Bus\Contracts\Dispatcher as DispatcherContract;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                DispatcherContract::class => DispatcherFactory::class,
                BatchRepository::class => DatabaseBatchRepository::class,
                DatabaseBatchRepository::class => DatabaseBatchRepositoryFactory::class,
            ],
        ];
    }
}
