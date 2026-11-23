<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data;

use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisManager;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Redis;

class ClientRedisProxy extends RedisProxy
{
    /**
     * Get the client of a connection this proxy holds.
     */
    public function client(): string
    {
        return 'client';
    }
}

class ForwardingRedisProxy extends RedisProxy
{
    /**
     * Handle the call in another way.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }
}

function test(RedisManager $manager, RedisProxy $proxy, RedisConnection $held, ClientRedisProxy $clientProxy, ForwardingRedisProxy $forwardingProxy): void
{
    Redis::connection()->client();
    $proxy->AUTH('secret');
    $manager->release();
    $proxy->getConnection();

    $held->client();
    $proxy->get('key');
    $clientProxy->client();
    $forwardingProxy->client();
}
