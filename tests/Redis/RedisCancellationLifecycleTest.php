<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Closure;
use Hypervel\ConnectionPool\Exceptions\ConnectionException;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Redis as RedisFacade;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Redis\Fixtures\RespServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Redis;
use RedisException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class RedisCancellationLifecycleTest extends TestCase
{
    #[TestWith([true], 'throwing cancellation')]
    #[TestWith([false], 'non-throwing cancellation')]
    public function testBlockedCommandCancellationInvalidatesAndReturnsTheLeaseWithoutEvents(bool $throwException): void
    {
        $server = new RespServer;
        $commandReceived = new Channel(1);
        $releaseServer = new Channel(1);
        $server->start(function ($client) use ($commandReceived, $releaseServer): void {
            $command = fread($client, 4096);

            $this->assertIsString($command);
            $this->assertStringContainsString('BLPOP', $command);
            $commandReceived->push(true);
            $releaseServer->pop(2.0);
        });

        [$host, $port] = $server->hostAndPort();
        $connectionName = 'canceled_command';
        $this->configureConnection($connectionName, $host, $port);
        $executed = 0;
        $failed = 0;
        RedisFacade::listen(static function (CommandExecuted $event) use (&$executed): void {
            ++$executed;
        });
        RedisFacade::listenForFailures(static function (CommandFailed $event) use (&$failed): void {
            ++$failed;
        });
        $connection = RedisFacade::connection($connectionName);
        $pool = $this->app->make(PoolManager::class)->pool($connectionName);
        // The one-connection pool establishes the only socket accepted by the test server,
        // then the canceled command reuses it.
        $eventConnection = $pool->borrow();

        try {
            $this->assertInstanceOf(RedisConnection::class, $eventConnection);
            $this->assertNotNull($eventConnection->getEventDispatcher());
        } finally {
            $pool->release($eventConnection);
        }

        try {
            $exception = $this->cancelAfter(
                static fn () => $connection->blpop('blocked', 0),
                $commandReceived,
                $throwException,
            );

            $this->assertInstanceOf(CanceledException::class, $exception);
            $this->assertSame('Executing Redis command [blpop] was canceled.', $exception->getMessage());
            $this->assertInstanceOf(RedisException::class, $exception->getPrevious());
            $this->assertSame(0, $executed);
            $this->assertSame(0, $failed);

            // The pool already owns this lease, so cancellation returns it invalidated.
            $this->assertSame(1, $pool->getManagedCount());
            $this->assertSame(1, $pool->getIdleCount());
            $pooledConnection = $pool->borrow();

            try {
                $this->assertInstanceOf(RedisConnection::class, $pooledConnection);
                $this->assertFalse($pooledConnection->check());
            } finally {
                $pool->release($pooledConnection);
            }
        } finally {
            $releaseServer->push(true);

            try {
                $server->wait();
            } finally {
                $this->app->make(PoolManager::class)->purge($connectionName);
            }
        }
    }

    public function testBlockedSelectCancellationRepairsPoolCapacity(): void
    {
        $server = new RespServer;
        $selectReceived = new Channel(1);
        $releaseServer = new Channel(1);
        $server->start(function ($client) use ($selectReceived, $releaseServer): void {
            $command = fread($client, 4096);

            $this->assertIsString($command);
            $this->assertStringContainsString('SELECT', $command);
            $selectReceived->push(true);
            $releaseServer->pop(2.0);
        });

        [$host, $port] = $server->hostAndPort();
        $connectionName = 'canceled_select';
        $this->configureConnection($connectionName, $host, $port, ['database' => 1]);
        $connection = RedisFacade::connection($connectionName);
        $pool = $this->app->make(PoolManager::class)->pool($connectionName);

        try {
            try {
                $exception = $this->cancelAfter(
                    static fn () => $connection->get('blocked'),
                    $selectReceived,
                );

                $this->assertInstanceOf(CanceledException::class, $exception);
                $this->assertSame('Connecting to Redis was canceled.', $exception->getMessage());
                $this->assertInstanceOf(RedisException::class, $exception->getPrevious());

                // Initial SELECT was canceled before admission, so the pool discards the lease.
                $this->assertSame(0, $pool->getManagedCount());
                $this->assertSame(0, $pool->getIdleCount());
            } finally {
                $releaseServer->push(true);
                $server->wait();
            }

            $capacityFailure = null;

            try {
                $pool->borrow();
            } catch (Throwable $throwable) {
                $capacityFailure = $throwable;
            }

            $this->assertTrue(
                $capacityFailure instanceof RedisException || $capacityFailure instanceof ConnectionException,
                sprintf(
                    'Expected the next checkout to reach the Redis transport, got [%s].',
                    $capacityFailure === null ? 'no exception' : $capacityFailure::class,
                ),
            );
        } finally {
            $this->app->make(PoolManager::class)->purge($connectionName);
        }
    }

    #[DataProvider('nativeClientOperations')]
    public function testNativeClientCancellationIsClassifiedAtItsBoundary(
        string $stallOn,
        Closure $operation,
        string $message,
        bool $throwException,
    ): void {
        $server = new RespServer;
        $entered = new Channel(1);
        $releaseServer = new Channel(1);
        $server->start(function ($client) use ($stallOn, $entered, $releaseServer): void {
            while (is_string($command = fread($client, 4096)) && $command !== '') {
                if (str_contains($command, $stallOn)) {
                    $entered->push(true);
                    $releaseServer->pop(2.0);

                    return;
                }

                fwrite($client, str_contains($command, 'MULTI') ? "+OK\r\n" : "+QUEUED\r\n");
            }
        });

        [$host, $port] = $server->hostAndPort();
        $connectionName = 'canceled_native_client';
        $this->configureConnection($connectionName, $host, $port);
        $redis = RedisFacade::connection($connectionName);

        try {
            $exception = $this->cancelAfter(static fn () => $operation($redis), $entered, $throwException);

            $this->assertInstanceOf(CanceledException::class, $exception);
            $this->assertSame($message, $exception->getMessage());
            $this->assertInstanceOf(RedisException::class, $exception->getPrevious());
        } finally {
            $releaseServer->push(true);

            try {
                $server->wait();
            } finally {
                $this->app->make(PoolManager::class)->purge($connectionName);
            }
        }
    }

    /**
     * Provide native-client operations that wait outside command dispatch, in both cancellation modes.
     */
    public static function nativeClientOperations(): iterable
    {
        $operations = [
            'transaction exec' => [
                'EXEC',
                static fn (RedisProxy $redis) => $redis->transaction(static fn (Redis $client) => $client->set('key', 'value')),
                'Executing the Redis transaction was canceled.',
            ],
            'held pipeline exec' => [
                'SET',
                static fn (RedisProxy $redis) => $redis->withConnection(
                    static fn (RedisConnection $connection) => $connection->pipeline()->set('key', 'value')->exec(),
                ),
                'Using a Redis connection was canceled.',
            ],
            'held discard' => [
                'DISCARD',
                static fn (RedisProxy $redis) => $redis->withConnection(static function (RedisConnection $connection): void {
                    $connection->multi();
                    $connection->discardTransaction();
                }),
                'Discarding the Redis transaction was canceled.',
            ],
        ];

        foreach ($operations as $name => $operation) {
            yield "{$name}, throwing" => [...$operation, true];
            yield "{$name}, non-throwing" => [...$operation, false];
        }
    }

    public function testFailureAfterAHandledCancellationKeepsItsRedisException(): void
    {
        $server = new RespServer;
        $releaseServer = new Channel(1);
        $server->start(static function () use ($releaseServer): void {
            $releaseServer->pop(2.0);
        });

        [$host, $port] = $server->hostAndPort();
        $connectionName = 'stale_cancellation';
        $this->configureConnection($connectionName, $host, $port, [
            'options' => ['prefix' => '', 'serializer' => Redis::SERIALIZER_PHP],
        ]);
        $redis = RedisFacade::connection($connectionName);

        try {
            // Connect first, because connecting waits and would reset the cancellation state.
            $this->assertIsString($redis->_serialize('value'));
            $coroutine = Coroutine::id();
            Coroutine::create(static function () use ($coroutine): void {
                usleep(10000);
                Coroutine::cancelById($coroutine);
            });

            $this->assertFalse((new Channel(1))->pop(1.0));
            $this->assertTrue(Coroutine::isCanceled());

            // The unserialize failure doesn't wait, so the earlier cancellation must not claim it.
            $this->expectException(RedisException::class);

            $redis->_unserialize('corrupt');
        } finally {
            $releaseServer->push(true);

            try {
                $server->wait();
            } finally {
                $this->app->make(PoolManager::class)->purge($connectionName);
            }
        }
    }

    /**
     * Configure shared Redis options and one connection for a local test server.
     *
     * @param array<string, mixed> $overrides
     */
    private function configureConnection(string $name, string $host, int $port, array $overrides = []): void
    {
        $config = $this->app->make('config');
        $config->set('database.redis.options', []);
        $connection = [
            'url' => null,
            'host' => $host,
            'port' => $port,
            'username' => null,
            'password' => null,
            'database' => 0,
            'timeout' => 0.5,
            'read_timeout' => 5.0,
            'events' => true,
            'max_retries' => 0,
            'options' => ['prefix' => ''],
            'pool' => [
                'min_retained_connections' => 0,
                'max_connections' => 1,
                'connect_timeout' => 0.5,
                'wait_timeout' => 0.1,
                'heartbeat_interval' => null,
                'heartbeat_timeout' => 0.1,
                'max_idle_time' => 60.0,
                'max_lifetime' => null,
            ],
        ];

        $config->set("database.redis.{$name}", array_replace($connection, $overrides));
    }

    /**
     * Cancel an operation after its native I/O boundary has been reached.
     */
    private function cancelAfter(callable $operation, Channel $entered, bool $throwException = true): Throwable
    {
        $result = new Channel(1);
        $coroutine = Coroutine::create(static function () use ($operation, $result): void {
            try {
                $operation();
                $result->push(true);
            } catch (Throwable $exception) {
                $result->push($exception);
            }
        });
        $enteredOperation = false;
        $cancellationRequested = false;

        try {
            $enteredOperation = $entered->pop(2.0);

            if ($enteredOperation) {
                $cancellationRequested = Coroutine::cancelById($coroutine->getId(), $throwException);
            }
        } finally {
            try {
                if (! $cancellationRequested && Coroutine::exists($coroutine->getId())) {
                    Coroutine::cancelById($coroutine->getId(), throwException: true);
                }
            } finally {
                Coroutine::join([$coroutine->getId()], 2.0);
            }
        }

        $this->assertTrue($enteredOperation);
        $this->assertTrue($cancellationRequested);
        $this->assertFalse(Coroutine::exists($coroutine->getId()));
        $outcome = $result->pop(2.0);
        $this->assertInstanceOf(Throwable::class, $outcome);

        return $outcome;
    }
}
