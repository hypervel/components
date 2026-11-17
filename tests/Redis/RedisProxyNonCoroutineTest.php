<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Redis;

class RedisProxyNonCoroutineTest extends TestCase
{
    use VerifiesDoubles;

    protected bool $runTestsInCoroutine = false;

    public function testMultiPinsConnectionUntilTerminalCleanup(): void
    {
        $this->assertCommandPinsConnection('multi', [], Double::for(Redis::class)->strict());
    }

    public function testPipelinePinsConnectionUntilTerminalCleanup(): void
    {
        $this->assertCommandPinsConnection('pipeline', [], Double::for(Redis::class)->strict());
    }

    public function testSelectPinsConnectionUntilTerminalCleanup(): void
    {
        $this->assertCommandPinsConnection('select', [2], true);
    }

    public function testWatchPinsConnectionUntilTerminalCleanup(): void
    {
        $this->assertCommandPinsConnection('watch', ['key'], true);
    }

    /**
     * Assert a successful stateful command pins and terminally releases its wrapper.
     */
    private function assertCommandPinsConnection(
        string $command,
        array $arguments,
        mixed $result,
    ): void {
        $client = Double::for(Redis::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $client->expects($command)->with(...$arguments)->returns($result);
        $connection = Double::for((new PhpRedisConnectionStub)->setActiveConnection($client))->passthru();
        $connection->expects('release');

        $pool = Double::for(RedisPool::class)->strict();
        $pool->expects('borrow')->returns($connection);
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('pool')->with('default')->returns($pool);
        $redis = new RedisProxy(
            $poolManager,
            'default',
            Double::for(RedisSentinelFactory::class)->strict(),
        );
        $contextKey = (fn (): string => $this->getContextKey())->call($redis);

        $this->assertSame($result, $redis->{$command}(...$arguments));
        $this->assertSame($connection, CoroutineContext::get($contextKey));

        $redis->releaseContextConnection();

        $this->assertFalse(CoroutineContext::has($contextKey));
    }
}
