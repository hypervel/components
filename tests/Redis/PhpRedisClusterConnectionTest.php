<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\ConnectionPool\Exceptions\ConnectionException;
use Hypervel\ConnectionPool\PoolOptions;
use Hypervel\Contracts\ConnectionPool\ConnectionPool;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Redis\Exceptions\InvalidRedisOptionException;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Redis\PhpRedisClusterConnection;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClusterClient;
use Hypervel\Tests\Redis\Fixtures\PhpRedisClusterConnectionStub;
use Hypervel\Tests\Redis\Fixtures\RespServer;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Redis;
use RedisCluster;
use RedisException;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class PhpRedisClusterConnectionTest extends TestCase
{
    use VerifiesDoubles;

    public function testClusterCreationPreservesExactCancellation(): void
    {
        $cancellation = new CanceledException('cluster creation canceled');

        try {
            new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig(), $cancellation) extends PhpRedisClusterConnection {
                public function __construct(
                    ContainerContract $container,
                    ConnectionPool $pool,
                    array $config,
                    private CanceledException $cancellation,
                ) {
                    parent::__construct($container, $pool, $config);
                }

                protected function formatClusterPassword(): mixed
                {
                    throw $this->cancellation;
                }
            };

            $this->fail('Expected the cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testClusterCreationPreservesTheUnderlyingFailure(): void
    {
        $failure = new RuntimeException('cluster unavailable');

        try {
            new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig(), $failure) extends PhpRedisClusterConnection {
                public function __construct(
                    ContainerContract $container,
                    ConnectionPool $pool,
                    array $config,
                    private RuntimeException $failure,
                ) {
                    parent::__construct($container, $pool, $config);
                }

                protected function formatClusterPassword(): mixed
                {
                    throw $this->failure;
                }
            };

            $this->fail('Expected Redis Cluster creation to fail.');
        } catch (ConnectionException $exception) {
            $this->assertSame('Connection reconnect failed: cluster unavailable', $exception->getMessage());
            $this->assertSame($failure, $exception->getPrevious());
        }
    }

    public function testIsClusterReturnsTrue(): void
    {
        $connection = new PhpRedisClusterConnectionStub;

        $this->assertTrue($connection->isCluster());
    }

    public function testTransformFiresInAtomicMode(): void
    {
        $connection = new PhpRedisClusterConnectionStub;

        // In atomic mode, isQueueingMode() returns false, so transforms fire
        $client = Double::for(RedisCluster::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $client->expects('setNx')
            ->with('key', 'value')
            ->returns(true);

        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $result = $connection->__call('setnx', ['key', 'value']);

        $this->assertSame(1, $result);
    }

    public function testTransformSkippedInMultiMode(): void
    {
        $connection = new PhpRedisClusterConnectionStub;

        // In multi mode, isQueueingMode() returns true, so transforms are skipped
        // and the raw command is forwarded directly to the client
        $client = Double::for(RedisCluster::class)->strict();
        $client->allows('getMode')->returns(Redis::MULTI);
        $client->expects('setnx')
            ->with('key', 'value')
            ->returns($client); // multi() returns self for chaining

        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        // Without isQueueingMode, this would call callSetnx() which calls setNx()
        // With isQueueingMode true, it calls setnx() directly on the client
        $result = $connection->__call('setnx', ['key', 'value']);

        $this->assertSame($client, $result);
    }

    public function testMastersReturnsClusterMasterNodes(): void
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.1', 6380]];
        $client = new FakeRedisClusterClient(masters: $masters);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);

        $this->assertSame($masters, $connection->masters());
    }

    public function testItFlushesAllMasterNodes(): void
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.1', 6380], ['127.0.0.1', 6381]];
        $client = new FakeRedisClusterClient(masters: $masters);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $result = $connection->__call('flushdb', []);

        $flushCalls = $client->getFlushdbCalls();
        $this->assertTrue($result);
        $this->assertCount(3, $flushCalls);
        $this->assertSame(['127.0.0.1', 6379], $flushCalls[0]['node']);
        $this->assertSame(['127.0.0.1', 6380], $flushCalls[1]['node']);
        $this->assertSame(['127.0.0.1', 6381], $flushCalls[2]['node']);
    }

    public function testItFlushesAllMasterNodesAsync(): void
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.1', 6380]];
        $client = new FakeRedisClusterClient(masters: $masters);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $result = $connection->__call('flushdb', ['ASYNC']);

        $rawCalls = $client->getRawCommandCalls();
        $this->assertTrue($result);
        $this->assertCount(2, $rawCalls);
        $this->assertSame([['127.0.0.1', 6379], 'flushdb', 'async'], $rawCalls[0]['args']);
        $this->assertSame([['127.0.0.1', 6380], 'flushdb', 'async'], $rawCalls[1]['args']);
    }

    public function testFlushdbAttemptsEveryMasterAndAggregatesFailures(): void
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.1', 6380], ['127.0.0.1', 6381]];
        $client = Double::for(RedisCluster::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $client->expects('_masters')->returns($masters);
        $client->expects('flushdb')->with($masters[0])->returns(true);
        $client->expects('flushdb')->with($masters[1])->returns(false);
        $client->expects('flushdb')->with($masters[2])->returns(true);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->assertFalse($connection->__call('flushdb', []));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testItScansStartingFromTheFirstMaster(bool $hasNextMaster): void
    {
        $masters = [['127.0.0.1', 6379]];
        if ($hasNextMaster) {
            $masters[] = ['127.0.0.1', 6380];
        }
        $nodeKey = '127.0.0.1:6379';
        $client = new FakeRedisClusterClient(
            masters: $masters,
            scanResults: [
                $nodeKey => [
                    ['keys' => ['key1', 'key2'], 'iterator' => 0],
                ],
            ],
        );

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $cursor = null;
        $result = $connection->scan($cursor, ['match' => '*', 'count' => 10]);

        $this->assertSame(['key1', 'key2'], $result[1]);
        $this->assertSame($cursor, $result[0]);
        if ($hasNextMaster) {
            $this->assertStringStartsWith('hypervel:', $cursor);
        } else {
            $this->assertSame(0, $cursor);
        }

        $scanCalls = $client->getScanCalls();
        $this->assertSame([['iterator' => null, 'node' => $masters[0], 'pattern' => '*', 'count' => 10]], $scanCalls);
    }

    #[TestWith([['127.0.0.1', 6380]])]
    #[TestWith(['option-node'])]
    public function testItScansUsingOptionNode(array|string $explicitNode): void
    {
        $nodeKey = is_array($explicitNode) ? implode(':', $explicitNode) : $explicitNode;
        $client = new FakeRedisClusterClient(
            masters: [['127.0.0.1', 6379], ['127.0.0.1', 6380]],
            scanResults: [
                $nodeKey => [
                    ['keys' => ['key3'], 'iterator' => 0],
                ],
            ],
        );

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $cursor = null;
        $result = $connection->scan($cursor, ['match' => '*', 'count' => 10, 'node' => $explicitNode]);

        $this->assertSame([0, ['key3']], $result);

        $scanCalls = $client->getScanCalls();
        $this->assertSame([['iterator' => null, 'node' => $explicitNode, 'pattern' => '*', 'count' => 10]], $scanCalls);
        $this->assertSame(0, $client->mastersRequested);
    }

    public function testItThrowsExceptionWithoutNodes(): void
    {
        $client = new FakeRedisClusterClient(masters: []);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();

        $this->expectExceptionObject(new InvalidArgumentException('No master nodes found in the cluster.'));

        $cursor = null;
        try {
            $connection->scan($cursor);
        } finally {
            $this->assertSame([], $client->getScanCalls());
        }
    }

    public function testItReturnsFalseWhenCursorIsZeroAndResultIsEmpty(): void
    {
        $master = ['127.0.0.1', 6379];
        $client = new FakeRedisClusterClient(masters: [$master]);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = 0;

        $this->assertFalse($connection->scan($cursor));
        $this->assertSame(0, $cursor);
        $this->assertSame([['iterator' => 0, 'node' => $master, 'pattern' => '*', 'count' => 10]], $client->getScanCalls());
    }

    public function testItScansEveryMasterInTurn(): void
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.2', 6379], ['127.0.0.3', 6379]];
        $keys = ['a', 'b', 'c'];
        $client = new FakeRedisClusterClient(masters: $masters, scanResults: [
            '127.0.0.1:6379' => [['keys' => ['a'], 'iterator' => 0]],
            '127.0.0.2:6379' => [['keys' => ['b'], 'iterator' => 0]],
            '127.0.0.3:6379' => [['keys' => ['c'], 'iterator' => 0]],
        ]);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = null;

        foreach ($keys as $key) {
            $page = $connection->scan($cursor);

            // A held connection must expose the same continuation by reference and in the tuple.
            $this->assertSame($page[0], $cursor);
            $this->assertSame([$key], $page[1]);
        }

        $this->assertSame(array_map(
            fn (array $master): array => ['iterator' => null, 'node' => $master, 'pattern' => '*', 'count' => 10],
            $masters,
        ), $client->getScanCalls());
        $this->assertSame(0, $cursor);
        $this->assertFalse($connection->scan($cursor));
        $this->assertSame([null, null, null, 0, 0, 0], array_column($client->getScanCalls(), 'iterator'));
        $this->assertSame([...$masters, ...$masters], array_column($client->getScanCalls(), 'node'));
    }

    #[DataProvider('firstScanPages')]
    public function testItResumesAMasterFromTheEncodedCursor(array $firstPage): void
    {
        $master = ['127.0.0.1', 6379];
        $client = new FakeRedisClusterClient(masters: [$master], scanResults: [
            '127.0.0.1:6379' => [
                ['keys' => $firstPage, 'iterator' => 42],
                ['keys' => ['last'], 'iterator' => 0],
            ],
        ]);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = null;

        [$reportedCursor, $keys] = $connection->scan($cursor);

        $this->assertStringStartsWith('hypervel:', $reportedCursor);
        $this->assertSame($reportedCursor, $cursor);
        $this->assertSame($firstPage, $keys);
        $this->assertSame([0, ['last']], $connection->scan($cursor));

        $this->assertSame([
            ['iterator' => null, 'node' => $master, 'pattern' => '*', 'count' => 10],
            ['iterator' => 42, 'node' => $master, 'pattern' => '*', 'count' => 10],
        ], $client->getScanCalls());
    }

    /**
     * Provide pages that require continuing on the same master.
     *
     * @return array<string, array{list<string>}>
     */
    public static function firstScanPages(): array
    {
        return [
            'matching keys' => [['first']],
            'no matching keys yet' => [[]],
        ];
    }

    public function testItKeepsScanningWhenAMasterReturnsNoKeys(): void
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.2', 6379]];
        $client = new FakeRedisClusterClient(masters: $masters, scanResults: [
            '127.0.0.1:6379' => [['keys' => [], 'iterator' => 0]],
            '127.0.0.2:6379' => [['keys' => ['key'], 'iterator' => 0]],
        ]);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = null;

        $this->assertSame([0, ['key']], $connection->scan($cursor));

        $this->assertSame([
            ['iterator' => null, 'node' => $masters[0], 'pattern' => '*', 'count' => 10],
            ['iterator' => null, 'node' => $masters[1], 'pattern' => '*', 'count' => 10],
        ], $client->getScanCalls());
    }

    public function testItPreservesLargeStringCursors(): void
    {
        $master = ['127.0.0.1', 6379];
        $largeCursor = '18446744073709551615';
        $client = new FakeRedisClusterClient(masters: [$master], scanResults: [
            '127.0.0.1:6379' => [
                ['keys' => ['first'], 'iterator' => $largeCursor],
                ['keys' => ['last'], 'iterator' => 0],
            ],
        ]);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = null;
        [$cursor] = $connection->scan($cursor);

        $this->assertSame([0, ['last']], $connection->scan($cursor));

        $this->assertSame([
            ['iterator' => null, 'node' => $master, 'pattern' => '*', 'count' => 10],
            ['iterator' => $largeCursor, 'node' => $master, 'pattern' => '*', 'count' => 10],
        ], $client->getScanCalls());
    }

    public function testItKeepsNodeAffinityWhenMastersAreReordered(): void
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.2', 6379]];
        $client = new FakeRedisClusterClient(masters: $masters, scanResults: [
            '127.0.0.1:6379' => [['keys' => ['a'], 'iterator' => 0]],
            '127.0.0.2:6379' => [['keys' => ['b'], 'iterator' => 0]],
        ]);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = null;
        [$cursor] = $connection->scan($cursor);
        $client->setMasters(array_reverse($masters));

        $this->assertSame([0, ['b']], $connection->scan($cursor));
        $this->assertSame(2, $client->mastersRequested);
        $this->assertSame([
            ['iterator' => null, 'node' => $masters[0], 'pattern' => '*', 'count' => 10],
            ['iterator' => null, 'node' => $masters[1], 'pattern' => '*', 'count' => 10],
        ], $client->getScanCalls());
    }

    public function testItContinuesWithAnotherMasterWhenTheCurrentMasterDisappears(): void
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.2', 6379]];
        $client = new FakeRedisClusterClient(masters: $masters, scanResults: [
            '127.0.0.1:6379' => [['keys' => ['a'], 'iterator' => 42]],
            '127.0.0.2:6379' => [['keys' => ['b'], 'iterator' => 0]],
        ]);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = null;
        [$cursor] = $connection->scan($cursor);
        $client->setMasters([$masters[1]]);

        $this->assertSame([0, ['b']], $connection->scan($cursor));
        $this->assertSame(2, $client->mastersRequested);
        $this->assertSame([
            ['iterator' => null, 'node' => $masters[0], 'pattern' => '*', 'count' => 10],
            ['iterator' => null, 'node' => $masters[1], 'pattern' => '*', 'count' => 10],
        ], $client->getScanCalls());
    }

    public function testInfoTransformIncludesTheDefaultNodeAndSections(): void
    {
        $defaultNode = ['127.0.0.1', 6379];
        $client = Double::for(RedisCluster::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $client->expects('_masters')->returns([$defaultNode]);
        $client->expects('info')
            ->with($defaultNode, 'server', 'memory')
            ->returns(['redis_version' => '8.0.0']);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->assertSame(
            ['redis_version' => '8.0.0'],
            $connection->__call('info', ['server', 'memory']),
        );
    }

    public function testInfoTransformSupportsAnUnfilteredRequest(): void
    {
        $defaultNode = ['127.0.0.1', 6379];
        $client = Double::for(RedisCluster::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $client->expects('_masters')->returns([$defaultNode]);
        $client->expects('info')
            ->with($defaultNode)
            ->returns(['redis_version' => '8.0.0', 'used_memory' => 1024]);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->assertSame(
            ['redis_version' => '8.0.0', 'used_memory' => 1024],
            $connection->__call('info', []),
        );
    }

    public function testPingTransformRoutesToTheDefaultNode(): void
    {
        $defaultNode = ['127.0.0.1', 6379];
        $client = Double::for(RedisCluster::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $client->expects('_masters')->returns([$defaultNode]);
        $client->expects('ping')->with($defaultNode)->returns(true);
        $client->expects('ping')->with($defaultNode, 'hello')->returns('hello');

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->assertTrue($connection->__call('ping', []));
        $this->assertSame('hello', $connection->__call('ping', ['hello']));
    }

    public function testExecuteRawRoutesByTheFirstArgumentAndNormalizesNullReplies(): void
    {
        $client = Double::for(RedisCluster::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $client->expects('rawCommand')
            ->with('{user}:1', 'GET', '{user}:1')
            ->returns(null);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->assertFalse($connection->__call('executeRaw', [['GET', '{user}:1']]));
    }

    public function testExecuteRawRoutesByTheFirstArgumentWhileQueueing(): void
    {
        $client = Double::for(RedisCluster::class)->strict();
        $client->expects('getMode')->returns(Redis::MULTI);
        $client->expects('rawCommand')
            ->with('{user}:1', 'GET', '{user}:1')
            ->returns($client);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->assertSame(
            $client,
            $connection->__call('executeRaw', [['GET', '{user}:1']]),
        );
    }

    public function testExecuteRawRoutesKeylessCommandsToTheDefaultNode(): void
    {
        $defaultNode = ['127.0.0.1', 6379];
        $client = Double::for(RedisCluster::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $client->expects('_masters')->returns([$defaultNode]);
        $client->expects('rawCommand')
            ->with($defaultNode, 'TIME')
            ->returns(['1', '2']);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->assertSame(['1', '2'], $connection->__call('executeRaw', [['TIME']]));
    }

    public function testEvalTransformRecursivelyNormalizesNullReplies(): void
    {
        $connection = new class extends PhpRedisClusterConnectionStub {
            public function normalizeNullRepliesForTest(mixed $result): mixed
            {
                return $this->normalizeNullReplies($result);
            }
        };

        $this->assertSame(
            [false, [1, false]],
            $connection->normalizeNullRepliesForTest([null, [1, null]]),
        );
    }

    public function testEvalshaTransformUsesTheTopologyAwareShaCache(): void
    {
        $script = 'return {KEYS[1], false, ARGV[1]}';
        $connection = new class extends PhpRedisClusterConnectionStub {
            public array $arguments = [];

            public function callEvalshaForTest(string $script, int $numkeys, mixed ...$arguments): mixed
            {
                return $this->callEvalsha($script, $numkeys, ...$arguments);
            }

            public function evalWithShaCache(string $script, array $keys = [], array $args = []): mixed
            {
                $this->arguments = [$script, $keys, $args];

                return 'result';
            }
        };

        $this->assertSame('result', $connection->callEvalshaForTest($script, 1, '{script}', 'argument'));
        $this->assertSame([$script, ['{script}'], ['argument']], $connection->arguments);
    }

    #[DataProvider('standaloneErrorSemantics')]
    public function testErrorClassificationPreservesStandaloneSemantics(string $error, bool $throws): void
    {
        $connection = new class extends PhpRedisClusterConnectionStub {
            public function standaloneWouldThrowForTest(string $error): bool
            {
                return $this->standaloneWouldThrow($error);
            }

            public function scriptExceptionForTest(string $error): Throwable
            {
                return $this->scriptException($error);
            }
        };

        $this->assertSame($throws, $connection->standaloneWouldThrowForTest($error));
        $this->assertInstanceOf(
            $throws ? RedisException::class : LuaScriptException::class,
            $connection->scriptExceptionForTest($error),
        );
    }

    public static function standaloneErrorSemantics(): array
    {
        return [
            'ordinary error' => ['ERR invalid command', false],
            'missing script' => ['NOSCRIPT missing script', false],
            'no quorum' => ['NOQUORUM unavailable', false],
            'no good slave' => ['NOGOODSLAVE unavailable', false],
            'wrong type' => ['WRONGTYPE invalid value type', false],
            'busy group' => ['BUSYGROUP group exists', false],
            'missing group' => ['NOGROUP group missing', false],
            'authentication error' => ['ERR AUTH invalid password', true],
            'out of memory' => ['OOM command not allowed', true],
            'busy script' => ['BUSY script is running', true],
            'read only replica' => ['READONLY replica', true],
            'master unavailable' => ['MASTERDOWN unavailable', true],
            'cluster unavailable' => ['CLUSTERDOWN unavailable', true],
            'moved slot' => ['MOVED 1 127.0.0.1:6380', true],
            'ask redirection' => ['ASK 1 127.0.0.1:6380', true],
            'loading dataset' => ['LOADING dataset', true],
            'retry later' => ['TRYAGAIN later', true],
            'cross slot' => ['CROSSSLOT different slots', true],
            'misconfiguration' => ['MISCONF persistence unavailable', true],
        ];
    }

    public function testFormatClusterPasswordReturnsArrayWhenUsernameAndPasswordProvided(): void
    {
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig(['username' => 'myuser', 'password' => 'mypass'])) extends PhpRedisClusterConnectionStub {
            public function formatClusterPasswordForTest(): mixed
            {
                return $this->formatClusterPassword();
            }
        };

        $this->assertSame(['myuser', 'mypass'], $connection->formatClusterPasswordForTest());
    }

    public function testFormatClusterPasswordPreservesZeroCredentials(): void
    {
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig(['username' => '0', 'password' => '0'])) extends PhpRedisClusterConnectionStub {
            public function formatClusterPasswordForTest(): mixed
            {
                return $this->formatClusterPassword();
            }
        };

        $this->assertSame(['0', '0'], $connection->formatClusterPasswordForTest());
    }

    public function testNormalizeClusterContextUnwrapsSslKey(): void
    {
        $result = $this->callNormalizeClusterContext([
            'ssl' => [
                'verify_peer' => false,
                'peer_name' => 'example.com',
            ],
        ]);

        $this->assertSame([
            'verify_peer' => false,
            'peer_name' => 'example.com',
        ], $result);
    }

    public function testNormalizeClusterContextUnwrapsStreamKey(): void
    {
        $result = $this->callNormalizeClusterContext([
            'stream' => [
                'verify_peer' => false,
            ],
        ]);

        $this->assertSame([
            'verify_peer' => false,
        ], $result);
    }

    public function testNormalizeClusterContextPassesThroughFlatArray(): void
    {
        $context = [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ];

        $result = $this->callNormalizeClusterContext($context);

        $this->assertSame($context, $result);
    }

    public function testNormalizeClusterContextSslKeyTakesPrecedenceOverFlatKeys(): void
    {
        $result = $this->callNormalizeClusterContext([
            'verify_peer' => true,
            'ssl' => [
                'verify_peer' => false,
            ],
        ]);

        $this->assertSame([
            'verify_peer' => false,
        ], $result);
    }

    #[DataProvider('clusterTransports')]
    public function testClusterSchemeSelectsTheExpectedTransport(string $scheme, array $context): void
    {
        $tls = $scheme === 'tls';
        $server = new RespServer(
            $tls ? 'tls://127.0.0.1:0' : 'tcp://127.0.0.1:0',
            $tls
                ? [
                    'ssl' => [
                        'local_cert' => __DIR__ . '/Fixtures/Tls/server.crt',
                        'local_pk' => __DIR__ . '/Fixtures/Tls/server.key',
                        'allow_self_signed' => true,
                    ],
                ]
                : [],
        );
        $bytes = null;
        $failure = null;
        [$host, $port] = $server->hostAndPort();
        $server->start(static function ($client) use (&$bytes): void {
            $bytes = stream_get_contents($client, 2);
        });

        try {
            new PhpRedisClusterConnection(
                $this->getContainer(),
                $this->getMockedPool(),
                $this->clusterConfig([
                    'scheme' => $scheme,
                    'context' => $context,
                    'cluster' => [
                        'enabled' => true,
                        'seeds' => ["{$scheme}://{$host}:{$port}"],
                    ],
                ]),
            );
        } catch (ConnectionException $exception) {
            $failure = $exception;
        } finally {
            $server->wait();
        }

        $this->assertNotNull($failure);
        $this->assertSame('*2', $bytes);
    }

    public static function clusterTransports(): array
    {
        return [
            'tcp' => ['tcp', []],
            'tls without custom context' => ['tls', []],
            'tls with custom context' => ['tls', [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]],
        ];
    }

    public function testClusterOptionsUseNativeFailoverAndPackIgnoreNumbersConstants(): void
    {
        if (! defined(Redis::class . '::OPT_PACK_IGNORE_NUMBERS')) {
            $this->markTestSkipped('PhpRedis does not support OPT_PACK_IGNORE_NUMBERS.');
        }

        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig(['options' => ['failover' => RedisCluster::FAILOVER_DISTRIBUTE, 'pack_ignore_numbers' => true]])) extends PhpRedisClusterConnectionStub {
            /**
             * Apply the configured options to the given client.
             */
            public function setOptionsForTest(RedisCluster $redis): void
            {
                $this->setOptions($redis);
            }
        };
        $redis = Double::for(RedisCluster::class)->strict();
        $redis->expects('setOption')
            ->with(RedisCluster::OPT_SLAVE_FAILOVER, RedisCluster::FAILOVER_DISTRIBUTE)
            ->returns(true);
        $redis->expects('setOption')
            ->with(Redis::OPT_PACK_IGNORE_NUMBERS, true)
            ->returns(true);
        $this->expectDefaultConnectionOptions($redis);

        $connection->setOptionsForTest($redis);
    }

    #[DataProvider('enabledClusterTcpKeepaliveConfigurations')]
    public function testClusterRejectsEnabledTcpKeepalive(array $config): void
    {
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig($config)) extends PhpRedisClusterConnectionStub {
            /**
             * Apply the configured options to the given client.
             */
            public function setOptionsForTest(RedisCluster $redis): void
            {
                $this->setOptions($redis);
            }
        };
        $redis = Double::for(RedisCluster::class)->strict();
        $this->expectDefaultConnectionOptions($redis);

        $this->expectExceptionObject(new InvalidRedisOptionException(
            'The redis option `tcp_keepalive` is not supported for Redis Cluster connections.'
        ));

        $connection->setOptionsForTest($redis);
    }

    /**
     * Provide Cluster configurations that enable TCP keepalive.
     */
    public static function enabledClusterTcpKeepaliveConfigurations(): array
    {
        return [
            'connection level' => [['tcp_keepalive' => 30]],
            'named option' => [['options' => ['tcp_keepalive' => 30]]],
            'native option' => [['options' => [Redis::OPT_TCP_KEEPALIVE => 30]]],
        ];
    }

    #[DataProvider('disabledClusterTcpKeepaliveConfigurations')]
    public function testClusterSkipsDisabledTcpKeepalive(array $config): void
    {
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig($config)) extends PhpRedisClusterConnectionStub {
            /**
             * Apply the configured options to the given client.
             */
            public function setOptionsForTest(RedisCluster $redis): void
            {
                $this->setOptions($redis);
            }
        };
        $redis = Double::for(RedisCluster::class)->strict();
        $this->expectDefaultConnectionOptions($redis);

        $connection->setOptionsForTest($redis);
    }

    /**
     * Provide Cluster configurations that disable TCP keepalive explicitly.
     */
    public static function disabledClusterTcpKeepaliveConfigurations(): array
    {
        return [
            'named option' => [['options' => ['tcp_keepalive' => 0]]],
            'native option' => [['options' => [Redis::OPT_TCP_KEEPALIVE => 0]]],
        ];
    }

    public function testFormatClusterPasswordReturnsPlainPasswordWithoutUsername(): void
    {
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig(['password' => 'mypass'])) extends PhpRedisClusterConnectionStub {
            public function formatClusterPasswordForTest(): mixed
            {
                return $this->formatClusterPassword();
            }
        };

        $this->assertSame('mypass', $connection->formatClusterPasswordForTest());
    }

    public function testFormatClusterPasswordReturnsNullWhenNoPasswordProvided(): void
    {
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig()) extends PhpRedisClusterConnectionStub {
            public function formatClusterPasswordForTest(): mixed
            {
                return $this->formatClusterPassword();
            }
        };

        $this->assertNull($connection->formatClusterPasswordForTest());
    }

    public function testFormatClusterPasswordReturnsPlainPasswordWhenUsernameIsEmpty(): void
    {
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig(['username' => '', 'password' => 'mypass'])) extends PhpRedisClusterConnectionStub {
            public function formatClusterPasswordForTest(): mixed
            {
                return $this->formatClusterPassword();
            }
        };

        $this->assertSame('mypass', $connection->formatClusterPasswordForTest());
    }

    public function testFormatClusterPasswordReturnsPlainPasswordWhenPasswordIsNotString(): void
    {
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig(['username' => 'myuser', 'password' => ['mypass']])) extends PhpRedisClusterConnectionStub {
            public function formatClusterPasswordForTest(): mixed
            {
                return $this->formatClusterPassword();
            }
        };

        $this->assertSame(['mypass'], $connection->formatClusterPasswordForTest());
    }

    public function testDefaultNodeIsCached(): void
    {
        $client = Double::for(RedisCluster::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $client->expects('_masters')
            ->returns([['127.0.0.1', 6379]]);
        $client->expects('ping')
            ->times(2)
            ->with(['127.0.0.1', 6379])
            ->returns(true);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->assertTrue($connection->ping());
        $this->assertTrue($connection->ping());
    }

    public function testDefaultNodeThrowsWhenNoMasters(): void
    {
        $client = Double::for(RedisCluster::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $client->expects('_masters')
            ->returns([]);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unable to determine default node');

        $connection->ping();
    }

    public function testConnectionRebuildsItsClientOnNextAcquisitionWithoutReplayingCommand(): void
    {
        $exception = new RedisException('Connection lost');
        $failedClient = Double::for(RedisCluster::class)->strict();
        $healthyClient = Double::for(RedisCluster::class)->strict();
        $this->expectDefaultConnectionOptions($failedClient);
        $this->expectDefaultConnectionOptions($healthyClient);
        $failedClient->expects('get')->with('foo')->throws($exception);
        $failedClient->expects('getLastError')->returns(null);
        $healthyClient->expects('get')->with('foo')->returns('bar');

        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig(), [$failedClient, $healthyClient]) extends PhpRedisClusterConnection {
            /**
             * Create a connection with replacement native clients.
             *
             * @param RedisCluster[] $clients
             */
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private array $clients,
            ) {
                parent::__construct($container, $pool, $config);
            }

            /**
             * Return the next native client.
             */
            protected function createRedisCluster(): RedisCluster
            {
                return array_shift($this->clients);
            }
        };

        try {
            $connection->__call('get', ['foo']);
            $this->fail('Expected the command failure to propagate.');
        } catch (RedisException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertFalse($connection->check());
        $this->assertSame($failedClient, $connection->client());
        $this->assertSame($connection, $connection->getActiveConnection());
        $this->assertSame($healthyClient, $connection->client());
        $this->assertTrue($connection->check());
        $this->assertSame('bar', $connection->__call('get', ['foo']));
    }

    public function testReconnectClearsCachedDefaultNode(): void
    {
        $pool = Double::for(ConnectionPool::class)->strict();
        $pool->allows('getOptions')->returns(PoolOptions::fromArray([]));

        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->allows('has')->returns(false);
        $container->allows('bound')->with('events')->returns(false);

        // First client: master is node A
        $clientA = Double::for(RedisCluster::class)->strict();
        $clientA->expects('_masters')->returns([['10.0.0.1', 6379]]);
        $clientA->allows('getMode')->returns(Redis::ATOMIC);
        $clientA->expects('ping')->with(['10.0.0.1', 6379])->returns(true);
        $clientA->allows('setOption')->returns(true);

        // Second client (after reconnect): master is node B
        $clientB = Double::for(RedisCluster::class)->strict();
        $clientB->expects('_masters')->returns([['10.0.0.2', 6379]]);
        $clientB->allows('getMode')->returns(Redis::ATOMIC);
        $clientB->expects('ping')->with(['10.0.0.2', 6379])->returns(true);
        $clientB->allows('setOption')->returns(true);

        $callCount = 0;
        $connection = new class($container->instance(), $pool, $this->clusterConfig(['cluster' => ['enabled' => true, 'seeds' => ['tcp://10.0.0.1:6379']]]), $clientA, $clientB, $callCount) extends PhpRedisClusterConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private RedisCluster $clientA,
                private RedisCluster $clientB,
                private int &$callCount,
            ) {
                // Store config without invoking the parent constructor's reconnect.
                \Hypervel\Redis\RedisConnection::__construct($container, $pool, $config);
                $this->reconnect();
            }

            protected function createRedisCluster(): RedisCluster
            {
                return $this->callCount++ === 0 ? $this->clientA : $this->clientB;
            }
        };

        $connection->shouldTransform(true);

        // First ping caches defaultNode as node A.
        $this->assertTrue($connection->ping());

        // Reconnect: should clear cached defaultNode
        $connection->reconnect();

        // The next ping must resolve node B from the replacement client.
        $this->assertTrue($connection->ping());
    }

    /**
     * Create a complete Cluster Redis connection record.
     */
    private function clusterConfig(array $overrides = []): array
    {
        return array_replace([
            'scheme' => 'tcp',
            'username' => null,
            'password' => null,
            'timeout' => 1.0,
            'read_timeout' => 0.0,
            'context' => [],
            'options' => [],
            'prefix' => null,
            'events' => false,
            'max_retries' => 3,
            'backoff_algorithm' => 'decorrelated_jitter',
            'backoff_base' => 100,
            'backoff_cap' => 1000,
            'tcp_keepalive' => 0,
            'pool' => [
                'min_retained_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat_interval' => null,
                'heartbeat_timeout' => 1.0,
                'max_idle_time' => 60.0,
                'max_lifetime' => null,
            ],
            'cluster' => [
                'enabled' => true,
                'seeds' => ['tcp://127.0.0.1:7000'],
            ],
        ], $overrides);
    }

    /**
     * Expect the default connection-level phpredis options.
     */
    private function expectDefaultConnectionOptions(RedisCluster $redis): void
    {
        $redis->expects('setOption')->with(Redis::OPT_MAX_RETRIES, 3)->returns(true);
        $redis->expects('setOption')
            ->with(Redis::OPT_BACKOFF_ALGORITHM, Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER)
            ->returns(true);
        $redis->expects('setOption')->with(Redis::OPT_BACKOFF_BASE, 100)->returns(true);
        $redis->expects('setOption')->with(Redis::OPT_BACKOFF_CAP, 1000)->returns(true);
    }

    /**
     * Get a mocked Redis pool.
     */
    private function getMockedPool(): ConnectionPool
    {
        $pool = Double::for(ConnectionPool::class)->strict();
        $pool->allows('getOptions')->returns(PoolOptions::fromArray([]));

        return $pool;
    }

    /**
     * Get a mocked container.
     */
    private function getContainer(): ContainerContract
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->allows('has')->returns(false);
        $container->allows('bound')->with('events')->returns(false);

        return $container->instance();
    }

    /**
     * Normalize an SSL context for a Redis Cluster connection.
     */
    private function callNormalizeClusterContext(array $context): array
    {
        return (new ClassInvoker(new PhpRedisClusterConnectionStub))->normalizeClusterContext($context);
    }
}
