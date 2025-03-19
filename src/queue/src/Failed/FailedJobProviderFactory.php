<?php

declare(strict_types=1);

namespace Hypervel\Queue\Failed;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hypervel\Cache\Contracts\Factory as CacheFactoryContract;
use Psr\Container\ContainerInterface;

class FailedJobProviderFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $config = $container->get(ConfigInterface::class)
            ->get('queue.failed', []);

        if (array_key_exists('driver', $config)
            && (is_null($config['driver']) || $config['driver'] === 'null')
        ) {
            return new NullFailedJobProvider();
        }

        if (isset($config['driver']) && $config['driver'] === 'file') {
            return new FileFailedJobProvider(
                $config['path'] ?? $this->getBasePath($container) . '/storage/framework/cache/failed-jobs.json',
                $config['limit'] ?? 100,
                fn () => $container->get(CacheFactoryContract::class)->store('file'),
            );
        }
        if (isset($config['driver']) && $config['driver'] === 'database-uuids') {
            return $this->databaseUuidFailedJobProvider($container, $config);
        }
        if (isset($config['table'])) {
            return $this->databaseFailedJobProvider($container, $config);
        }

        return new NullFailedJobProvider();
    }

    /**
     * Create a new database failed job provider.
     */
    protected function databaseFailedJobProvider(ContainerInterface $container, array $config): DatabaseFailedJobProvider
    {
        return new DatabaseFailedJobProvider(
            $container->get(ConnectionResolverInterface::class),
            $config['table'],
            $config['database']
        );
    }

    /**
     * Create a new database failed job provider that uses UUIDs as IDs.
     */
    protected function databaseUuidFailedJobProvider(ContainerInterface $container, array $config): DatabaseUuidFailedJobProvider
    {
        return new DatabaseUuidFailedJobProvider(
            $container->get(ConnectionResolverInterface::class),
            $config['table'],
            $config['database']
        );
    }

    protected function getBasePath(ContainerInterface $container): string
    {
        return method_exists($container, 'basePath')
            ? $container->basePath()
            : BASE_PATH;
    }
}
