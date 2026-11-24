<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\BatchFactory;
use Hypervel\Bus\RedisBatchRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Redis;
use RedisException;

class RedisBatchRepositoryTest extends TestCase
{
    use VerifiesDoubles;

    public function testAFailoverErrorWhileRunningPipelinedScriptsInvalidatesTheConnection(): void
    {
        $failure = new RedisException("READONLY You can't write against a read only replica.");
        $client = $this->pipelineClient();
        $client->expects('exec')->throws($failure);

        [$caught, $invalid] = $this->deleteTwoBatchResultsThrough($client);

        $this->assertSame($failure, $caught);
        $this->assertTrue($invalid);
    }

    public function testAScriptErrorInAPipelineIsReportedAsALuaScriptError(): void
    {
        $client = $this->pipelineClient();
        $client->expects('exec')->returns([1, false]);
        $client->expects('getLastError')->returns('WRONGTYPE Operation against a key holding the wrong kind of value');

        [$caught, $invalid] = $this->deleteTwoBatchResultsThrough($client);

        $this->assertInstanceOf(LuaScriptException::class, $caught);
        $this->assertSame(
            'Unable to run the Redis batch script: WRONGTYPE Operation against a key holding the wrong kind of value',
            $caught->getMessage(),
        );
        $this->assertFalse($invalid);
    }

    private function pipelineClient(): Redis&DoubleInterface
    {
        $client = Double::for(Redis::class)->strict();
        $client->allows('getOption')->with(Redis::OPT_SERIALIZER)->returns(Redis::SERIALIZER_NONE);
        $client->allows('getOption')->with(Redis::OPT_COMPRESSION)->returns(Redis::COMPRESSION_NONE);
        $client->expects('clearLastError')->returns(true);
        $client->expects('pipeline')->returns($client);
        $client->expects('eval')->times(2)->returns($client);

        return $client;
    }

    /**
     * @return array{null|LuaScriptException|RedisException, bool} the failure and whether the connection was invalidated
     */
    private function deleteTwoBatchResultsThrough(Redis&DoubleInterface $client): array
    {
        $connection = new class extends PhpRedisConnectionStub {
            public function release(): void
            {
            }

            public function isInvalidForTest(): bool
            {
                return $this->invalid;
            }
        };
        $connection->setActiveConnection($client);

        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('borrow')->returns($connection);
        $manager = Double::for(new PoolManager(new Container))->passthru();
        $manager->allows('pool')->with('default')->returns($pool);
        $redis = Double::for(RedisFactory::class)->strict();
        $redis->allows('connection')->with('default')->returns(
            Double::for(new RedisProxy($manager, 'default', new RedisSentinelFactory))->passthru()
        );

        $repository = new class(new BatchFactory(Double::for(QueueFactory::class)->strict()), Double::for(ConnectionResolverInterface::class)->strict(), 'job_batches', null, 'job_batch_results', $redis, 'default', 'job_batch_deletions') extends RedisBatchRepository {
            public function deleteResultsOf(array $ids): void
            {
                $this->deleteJobResults($ids);
            }
        };
        $caught = null;

        try {
            $repository->deleteResultsOf(['first', 'second']);
        } catch (LuaScriptException|RedisException $caught) {
        }

        return [$caught, $connection->isInvalidForTest()];
    }
}
