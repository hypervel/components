<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\ConnectionPool\Exceptions\ConnectionException;
use Hypervel\ConnectionPool\PoolOptions;
use Hypervel\Contracts\ConnectionPool\ConnectionPool;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Redis\PhpRedisClusterConnection;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClusterClient;
use Hypervel\Tests\Redis\Fixtures\PhpRedisClusterConnectionStub;
use Hypervel\Tests\Redis\Fixtures\RespServer;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;
use RedisCluster;
use RedisException;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class PhpRedisClusterConnectionTest extends TestCase
{
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
        $client = m::mock(RedisCluster::class);
        $client->shouldReceive('getMode')->andReturn(Redis::ATOMIC);
        $client->shouldReceive('setNx')
            ->once()
            ->with('key', 'value')
            ->andReturn(true);

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
        $client = m::mock(RedisCluster::class);
        $client->shouldReceive('getMode')->andReturn(Redis::MULTI);
        $client->shouldReceive('setnx')
            ->once()
            ->with('key', 'value')
            ->andReturn($client); // multi() returns self for chaining

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

    public function testFlushdbSyncFlushesAllMasterNodes(): void
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

    public function testFlushdbAsyncUsesRawCommandOnAllMasters(): void
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
        $client = m::mock(RedisCluster::class);
        $client->allows('getMode')->andReturn(Redis::ATOMIC);
        $client->expects('_masters')->once()->andReturn($masters);
        $client->expects('flushdb')->with($masters[0])->andReturnTrue();
        $client->expects('flushdb')->with($masters[1])->andReturnFalse();
        $client->expects('flushdb')->with($masters[2])->andReturnTrue();

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->assertFalse($connection->__call('flushdb', []));
    }

    public function testScanTransformStartsWithTheFirstMaster(): void
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.1', 6380]];
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
        $this->assertStringStartsWith('hypervel:', $cursor);

        $scanCalls = $client->getScanCalls();
        $this->assertCount(1, $scanCalls);
        $this->assertSame(['127.0.0.1', 6379], $scanCalls[0]['node']);
    }

    public function testScanTransformUsesExplicitNodeOption(): void
    {
        $explicitNode = ['127.0.0.1', 6380];
        $nodeKey = '127.0.0.1:6380';
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
        $this->assertCount(1, $scanCalls);
        $this->assertSame($explicitNode, $scanCalls[0]['node']);
    }

    public function testItThrowsExceptionWithoutNodes(): void
    {
        $client = m::mock(RedisCluster::class);
        $client->expects('_masters')->andReturn([]);
        $client->shouldNotReceive('scan');
        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();

        $this->expectExceptionObject(new InvalidArgumentException('No master nodes found in the cluster.'));

        $cursor = null;
        $connection->scan($cursor);
    }

    public function testItReturnsFalseWhenCursorIsZeroAndResultIsEmpty(): void
    {
        $master = ['127.0.0.1', 6379];
        $client = m::mock(RedisCluster::class);
        $client->expects('_masters')->andReturn([$master]);
        $client->expects('scan')->with(0, $master, '*', 10)->andReturnFalse();
        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = 0;

        $this->assertFalse($connection->scan($cursor));
        $this->assertSame(0, $cursor);
    }

    public function testItScansEveryMasterInTurn(): void
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.2', 6379], ['127.0.0.3', 6379]];
        $keys = ['a', 'b', 'c'];
        $client = m::mock(RedisCluster::class);
        $client->allows('_masters')->andReturn($masters);

        foreach ($masters as $index => $master) {
            $key = $keys[$index];
            $client->expects('scan')->with(null, $master, '*', 10)
                ->andReturnUsing(function (&$cursor) use ($key): array {
                    $cursor = 0;

                    return [$key];
                });
            $client->expects('scan')->with(0, $master, '*', 10)->andReturnFalse();
        }

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = null;

        foreach ($keys as $key) {
            $page = $connection->scan($cursor);

            // A held connection must expose the same continuation by reference and in the tuple.
            $this->assertSame($page[0], $cursor);
            $this->assertSame([$key], $page[1]);
        }

        $this->assertSame(0, $cursor);
        $this->assertFalse($connection->scan($cursor));
    }

    #[DataProvider('firstScanPages')]
    public function testItResumesAMasterFromTheEncodedCursor(array $firstPage): void
    {
        $master = ['127.0.0.1', 6379];
        $client = m::mock(RedisCluster::class);
        $client->allows('_masters')->andReturn([$master]);
        $client->expects('scan')->with(null, $master, '*', 10)
            ->andReturnUsing(function (&$cursor) use ($firstPage): array {
                $cursor = 42;

                return $firstPage;
            });
        $client->expects('scan')->with(42, $master, '*', 10)
            ->andReturnUsing(function (&$cursor): array {
                $cursor = 0;

                return ['last'];
            });
        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = null;

        [$reportedCursor, $keys] = $connection->scan($cursor);

        $this->assertStringStartsWith('hypervel:', $reportedCursor);
        $this->assertSame($reportedCursor, $cursor);
        $this->assertSame($firstPage, $keys);
        $this->assertSame([0, ['last']], $connection->scan($cursor));
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
        $client = m::mock(RedisCluster::class);
        $client->allows('_masters')->andReturn($masters);
        $client->expects('scan')->with(null, $masters[0], '*', 10)
            ->andReturnUsing(function (&$cursor): array {
                $cursor = 0;

                return [];
            });
        $client->expects('scan')->with(null, $masters[1], '*', 10)
            ->andReturnUsing(function (&$cursor): array {
                $cursor = 0;

                return ['key'];
            });
        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = null;

        $this->assertSame([0, ['key']], $connection->scan($cursor));
    }

    public function testItPreservesLargeStringCursors(): void
    {
        $master = ['127.0.0.1', 6379];
        $largeCursor = '18446744073709551615';
        $client = m::mock(RedisCluster::class);
        $client->allows('_masters')->andReturn([$master]);
        $client->expects('scan')->with(null, $master, '*', 10)
            ->andReturnUsing(function (&$cursor) use ($largeCursor): array {
                $cursor = $largeCursor;

                return ['first'];
            });
        $client->expects('scan')->with($largeCursor, $master, '*', 10)
            ->andReturnUsing(function (&$cursor): array {
                $cursor = 0;

                return ['last'];
            });
        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = null;
        [$cursor] = $connection->scan($cursor);

        $this->assertSame([0, ['last']], $connection->scan($cursor));
    }

    public function testItKeepsNodeAffinityWhenMastersAreReordered(): void
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.2', 6379]];
        $client = m::mock(RedisCluster::class);
        $client->expects('_masters')->twice()->andReturn($masters, array_reverse($masters));

        foreach ($masters as $index => $master) {
            $client->expects('scan')->with(null, $master, '*', 10)
                ->andReturnUsing(function (&$cursor) use ($index): array {
                    $cursor = 0;

                    return [$index === 0 ? 'a' : 'b'];
                });
        }

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = null;
        [$cursor] = $connection->scan($cursor);

        $this->assertSame([0, ['b']], $connection->scan($cursor));
    }

    public function testItContinuesWithAnotherMasterWhenTheCurrentMasterDisappears(): void
    {
        $masters = [['127.0.0.1', 6379], ['127.0.0.2', 6379]];
        $client = m::mock(RedisCluster::class);
        $client->expects('_masters')->twice()->andReturn($masters, [$masters[1]]);
        $client->expects('scan')->with(null, $masters[0], '*', 10)
            ->andReturnUsing(function (&$cursor): array {
                $cursor = 42;

                return ['a'];
            });
        $client->expects('scan')->with(null, $masters[1], '*', 10)
            ->andReturnUsing(function (&$cursor): array {
                $cursor = 0;

                return ['b'];
            });
        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client)->shouldTransform();
        $cursor = null;
        [$cursor] = $connection->scan($cursor);

        $this->assertSame([0, ['b']], $connection->scan($cursor));
    }

    public function testInfoTransformIncludesTheDefaultNodeAndSections(): void
    {
        $defaultNode = ['127.0.0.1', 6379];
        $client = m::mock(RedisCluster::class);
        $client->allows('getMode')->andReturn(Redis::ATOMIC);
        $client->expects('_masters')->once()->andReturn([$defaultNode]);
        $client->expects('info')
            ->with($defaultNode, 'server', 'memory')
            ->andReturn(['redis_version' => '8.0.0']);

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
        $client = m::mock(RedisCluster::class);
        $client->allows('getMode')->andReturn(Redis::ATOMIC);
        $client->expects('_masters')->once()->andReturn([$defaultNode]);
        $client->expects('info')
            ->with($defaultNode)
            ->andReturn(['redis_version' => '8.0.0', 'used_memory' => 1024]);

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
        $client = m::mock(RedisCluster::class);
        $client->allows('getMode')->andReturn(Redis::ATOMIC);
        $client->expects('_masters')->once()->andReturn([$defaultNode]);
        $client->expects('ping')->with($defaultNode)->andReturnTrue();
        $client->expects('ping')->with($defaultNode, 'hello')->andReturn('hello');

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->assertTrue($connection->__call('ping', []));
        $this->assertSame('hello', $connection->__call('ping', ['hello']));
    }

    public function testExecuteRawRoutesByTheFirstArgumentAndNormalizesNullReplies(): void
    {
        $client = m::mock(RedisCluster::class);
        $client->allows('getMode')->andReturn(Redis::ATOMIC);
        $client->expects('rawCommand')
            ->with('{user}:1', 'GET', '{user}:1')
            ->andReturnNull();

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->assertFalse($connection->__call('executeRaw', [['GET', '{user}:1']]));
    }

    public function testExecuteRawRoutesByTheFirstArgumentWhileQueueing(): void
    {
        $client = m::mock(RedisCluster::class);
        $client->expects('getMode')->andReturn(Redis::MULTI);
        $client->expects('rawCommand')
            ->with('{user}:1', 'GET', '{user}:1')
            ->andReturn($client);

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
        $client = m::mock(RedisCluster::class);
        $client->allows('getMode')->andReturn(Redis::ATOMIC);
        $client->expects('_masters')->once()->andReturn([$defaultNode]);
        $client->expects('rawCommand')
            ->with($defaultNode, 'TIME')
            ->andReturn(['1', '2']);

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

    public function testNormalizeClusterContextAcceptsEverySupportedShape(): void
    {
        $connection = new class extends PhpRedisClusterConnectionStub {
            public function normalizeClusterContextForTest(array $context): array
            {
                return $this->normalizeClusterContext($context);
            }
        };
        $options = ['verify_peer' => false, 'cafile' => '/tmp/ca.pem'];

        $this->assertSame($options, $connection->normalizeClusterContextForTest($options));
        $this->assertSame($options, $connection->normalizeClusterContextForTest(['ssl' => $options]));
        $this->assertSame($options, $connection->normalizeClusterContextForTest(['stream' => $options]));
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
            fwrite($client, "-ERR test endpoint is not a Redis Cluster\r\n");
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

    public function testClusterOptionsUseNativeFailoverAndTcpKeepaliveConstants(): void
    {
        if (! defined(Redis::class . '::OPT_PACK_IGNORE_NUMBERS')) {
            $this->markTestSkipped('PhpRedis does not support OPT_PACK_IGNORE_NUMBERS.');
        }

        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig(['options' => ['failover' => RedisCluster::FAILOVER_DISTRIBUTE, 'tcp_keepalive' => 30, 'pack_ignore_numbers' => true]])) extends PhpRedisClusterConnectionStub {
            public function setOptionsForTest(RedisCluster $redis): void
            {
                $this->setOptions($redis);
            }
        };
        $redis = m::mock(RedisCluster::class);
        $redis->expects('setOption')
            ->with(RedisCluster::OPT_SLAVE_FAILOVER, RedisCluster::FAILOVER_DISTRIBUTE)
            ->andReturnTrue();
        $redis->expects('setOption')
            ->with(Redis::OPT_TCP_KEEPALIVE, 30)
            ->andReturnTrue();
        $redis->expects('setOption')
            ->with(Redis::OPT_PACK_IGNORE_NUMBERS, true)
            ->andReturnTrue();
        $this->expectDefaultConnectionOptions($redis);

        $connection->setOptionsForTest($redis);
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
        $client = m::mock(RedisCluster::class);
        $client->allows('getMode')->andReturn(Redis::ATOMIC);
        $client->shouldReceive('_masters')
            ->once()
            ->andReturn([['127.0.0.1', 6379]]);
        $client->shouldReceive('ping')
            ->twice()
            ->with(['127.0.0.1', 6379])
            ->andReturnTrue();

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->assertTrue($connection->ping());
        $this->assertTrue($connection->ping());
    }

    public function testDefaultNodeThrowsWhenNoMasters(): void
    {
        $client = m::mock(RedisCluster::class);
        $client->allows('getMode')->andReturn(Redis::ATOMIC);
        $client->shouldReceive('_masters')
            ->once()
            ->andReturn([]);

        $connection = new PhpRedisClusterConnectionStub;
        $connection->setActiveConnection($client);
        $connection->shouldTransform(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to determine default node');

        $connection->ping();
    }

    public function testConnectionRebuildsItsClientOnNextAcquisitionWithoutReplayingCommand(): void
    {
        $exception = new RedisException('Connection lost');
        $failedClient = m::mock(RedisCluster::class);
        $healthyClient = m::mock(RedisCluster::class);
        $this->expectDefaultConnectionOptions($failedClient);
        $this->expectDefaultConnectionOptions($healthyClient);
        $failedClient->expects('get')->once()->with('foo')->andThrow($exception);
        $failedClient->expects('getLastError')->andReturnNull();
        $healthyClient->expects('get')->once()->with('foo')->andReturn('bar');

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
        $pool = m::mock(ConnectionPool::class);
        $pool->shouldReceive('getOptions')->andReturn(PoolOptions::fromArray([]));

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->andReturn(false);
        $container->shouldReceive('bound')->with('events')->andReturn(false);

        // First client: master is node A
        $clientA = m::mock(RedisCluster::class);
        $clientA->shouldReceive('_masters')->once()->andReturn([['10.0.0.1', 6379]]);
        $clientA->allows('getMode')->andReturn(Redis::ATOMIC);
        $clientA->expects('ping')->with(['10.0.0.1', 6379])->andReturnTrue();
        $clientA->shouldReceive('setOption')->andReturnTrue();

        // Second client (after reconnect): master is node B
        $clientB = m::mock(RedisCluster::class);
        $clientB->shouldReceive('_masters')->once()->andReturn([['10.0.0.2', 6379]]);
        $clientB->allows('getMode')->andReturn(Redis::ATOMIC);
        $clientB->expects('ping')->with(['10.0.0.2', 6379])->andReturnTrue();
        $clientB->shouldReceive('setOption')->andReturnTrue();

        $callCount = 0;
        $connection = new class($container, $pool, $this->clusterConfig(['cluster' => ['enabled' => true, 'seeds' => ['tcp://10.0.0.1:6379']]]), $clientA, $clientB, $callCount) extends PhpRedisClusterConnection {
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

        // Mockery's ->once() on each client's _masters() verifies each was called exactly once,
        // proving the cache was cleared and re-populated after reconnect.
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
        $redis->expects('setOption')->with(Redis::OPT_MAX_RETRIES, 3)->andReturnTrue();
        $redis->expects('setOption')
            ->with(Redis::OPT_BACKOFF_ALGORITHM, Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER)
            ->andReturnTrue();
        $redis->expects('setOption')->with(Redis::OPT_BACKOFF_BASE, 100)->andReturnTrue();
        $redis->expects('setOption')->with(Redis::OPT_BACKOFF_CAP, 1000)->andReturnTrue();
    }

    /**
     * Get a mocked Redis pool.
     */
    private function getMockedPool(): ConnectionPool
    {
        $pool = m::mock(ConnectionPool::class);
        $pool->shouldReceive('getOptions')->andReturn(PoolOptions::fromArray([]));

        return $pool;
    }

    /**
     * Get a mocked container.
     */
    private function getContainer(): ContainerContract
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('has')->andReturn(false);
        $container->shouldReceive('bound')->with('events')->andReturn(false);

        return $container;
    }
}
