<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Contracts\Container\Container;

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
