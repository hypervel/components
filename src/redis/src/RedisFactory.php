<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Context\ApplicationContext;
use Hypervel\Redis\Pool\PoolFactory;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Redis\Exceptions\InvalidRedisProxyException;

class RedisFactory
{
    /**
     * @var RedisProxy[]
     */
    protected array $proxies = [];

    protected ?PoolFactory $poolFactory;

    /**
     * Create a new Redis factory instance.
     */
    public function __construct(ConfigInterface $config, ?PoolFactory $poolFactory = null)
    {
        $this->poolFactory = $poolFactory;
        $redisConfig = $config->get('redis');

        foreach ($redisConfig as $poolName => $item) {
            $this->proxies[$poolName] = new RedisProxy($this->resolvePoolFactory(), $poolName);
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

    /**
     * Resolve the Redis pool factory instance.
     */
    protected function resolvePoolFactory(): PoolFactory
    {
        if ($this->poolFactory instanceof PoolFactory) {
            return $this->poolFactory;
        }

        if (! ApplicationContext::hasContainer()) {
            throw new InvalidRedisProxyException('Invalid Redis proxy.');
        }

        return $this->poolFactory = ApplicationContext::getContainer()->get(PoolFactory::class);
    }
}
