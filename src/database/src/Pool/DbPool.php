<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\ConnectionInterface;
use Hypervel\Pool\Frequency;
use Hypervel\Pool\Pool;
use Hypervel\Support\Arr;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Database connection pool.
 *
 * Extends the base Pool to create PooledConnection instances that wrap
 * our Laravel-ported Connection class.
 */
class DbPool extends Pool
{
    protected array $config;

    public function __construct(
        ContainerInterface $container,
        protected string $name
    ) {
        $configService = $container->get(ConfigInterface::class);
        $key = sprintf('databases.%s', $this->name);

        if (! $configService->has($key)) {
            throw new InvalidArgumentException(sprintf('Database connection [%s] not configured.', $this->name));
        }

        // Include the connection name in the config
        $this->config = $configService->get($key);
        $this->config['name'] = $name;

        // Extract pool options
        $poolOptions = Arr::get($this->config, 'pool', []);

        $this->frequency = new Frequency($this);

        parent::__construct($container, $poolOptions);
    }

    /**
     * Get the pool name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Create a new pooled connection.
     */
    protected function createConnection(): ConnectionInterface
    {
        return new PooledConnection($this->container, $this, $this->config);
    }
}
