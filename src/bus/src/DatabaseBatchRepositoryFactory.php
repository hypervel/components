<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Database\ConnectionResolverInterface;
use Psr\Container\ContainerInterface;

class DatabaseBatchRepositoryFactory
{
    public function __invoke(ContainerInterface $container): DatabaseBatchRepository
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
