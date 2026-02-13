<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConnectionResolverInterface;

class DatabaseBatchRepositoryFactory
{
    public function __invoke(Container $container): DatabaseBatchRepository
    {
        $config = $container->make('config');

        return new DatabaseBatchRepository(
            $container->make(BatchFactory::class),
            $container->make(ConnectionResolverInterface::class),
            $config->get('queue.batching.table', 'job_batches'),
            $config->get('queue.batching.database')
        );
    }
}
