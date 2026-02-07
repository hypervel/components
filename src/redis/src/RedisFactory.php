<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Redis\Exceptions\InvalidRedisProxyException;

class RedisFactory
{
    /**
     * @var RedisProxy[]
     */
    protected array $proxies = [];

    /**
     * Create a new Redis factory instance.
     */
    public function __construct(RedisConfig $config, ContainerContract $container)
    {
        foreach ($config->connectionNames() as $poolName) {
            $this->proxies[$poolName] = $container->make(RedisProxy::class, ['pool' => $poolName]);
        }
    }

    /**
     * Get a Redis proxy by pool name.
     */
    public function get(string $poolName): RedisProxy
    {
        $proxy = $this->proxies[$poolName] ?? null;
        if (! $proxy instanceof RedisProxy) {
            throw new InvalidRedisProxyException('Invalid Redis proxy.');
        }

        return $proxy;
    }
}
