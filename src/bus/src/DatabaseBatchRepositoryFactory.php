<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConnectionResolverInterface;

class DatabaseBatchRepositoryFactory
{
    public function __invoke(Container $container): DatabaseBatchRepository
    {
        $config = $container->get('config');

        return new DatabaseBatchRepository(
            $container->get(BatchFactory::class),
            $container->get(ConnectionResolverInterface::class),
            $config->get('queue.batching.table', 'job_batches'),
            $config->get('queue.batching.database')
        );
    }
}
