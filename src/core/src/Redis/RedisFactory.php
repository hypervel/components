<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Exception\InvalidRedisProxyException;

use function Hyperf\Support\make;

class RedisFactory
{
    /**
     * @var RedisProxy[]
     */
    protected array $proxies = [];

    public function __construct(ConfigInterface $config)
    {
        $redisConfig = $config->get('redis');

        foreach ($redisConfig as $poolName => $item) {
            $this->proxies[$poolName] = make(RedisProxy::class, ['pool' => $poolName]);
        }
    }

    public function get(string $poolName): RedisProxy
    {
        $proxy = $this->proxies[$poolName] ?? null;
        if (! $proxy instanceof RedisProxy) {
            throw new InvalidRedisProxyException('Invalid Redis proxy.');
        }

        return $proxy;
    }
}
