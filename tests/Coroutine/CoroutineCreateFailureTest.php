<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Closure;
use Hypervel\Concurrency\CoroutineDriver;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ParallelExecutionException;
use Hypervel\Coroutine\Parallel;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Coroutine\Waiter;
use Hypervel\Database\Connection as DatabaseConnection;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Engine\SafeSocket;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Redis as NativeRedis;
use ReflectionClass;
use ReflectionProperty;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\Socket as SwooleSocket;

use function Hypervel\Coroutine\co;
use function Hypervel\Coroutine\go;

class CoroutineCreateFailureTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testPublicCreationHelpersUseTheThrowingContract(): void
    {
        $this->atCoroutineLimit(function (): void {
            foreach ([
                static fn (): int => Coroutine::create(static fn (): null => null),
                static fn (): int => Coroutine::fork(static fn (): null => null),
                static fn (): int => go(static fn (): null => null),
                static fn (): int => co(static fn (): null => null),
            ] as $create) {
                try {
                    $create();
                    $this->fail('Expected coroutine creation to fail.');
                } catch (CoroutineCreateException) {
                    $this->addToAssertionCount(1);
                }
            }
        });
    }

    #[RunInSeparateProcess]
    public function testConcurrentRestoresCapacityWhenCreationFails(): void
    {
        $this->atCoroutineLimit(function (): void {
            $concurrent = new Concurrent(1);

            try {
                $concurrent->create(static fn (): null => null);
                $this->fail('Expected coroutine creation to fail.');
            } catch (CoroutineCreateException) {
                $this->assertSame(0, $concurrent->length());
            }

            try {
                $concurrent->fork(static fn (): null => null);
                $this->fail('Expected coroutine creation to fail.');
            } catch (CoroutineCreateException) {
                $this->assertSame(0, $concurrent->length());
            }
        });
    }

    #[RunInSeparateProcess]
    public function testWaitConcurrentBalancesItsWaitGroupWhenCreationFails(): void
    {
        $this->atCoroutineLimit(function (): void {
            $concurrent = new WaitConcurrent(1);

            try {
                $concurrent->create(static fn (): null => null);
                $this->fail('Expected coroutine creation to fail.');
            } catch (CoroutineCreateException) {
                $this->assertSame(0, $concurrent->length());
                $this->assertTrue($concurrent->wait(0.001));
            }
        });
    }

    #[RunInSeparateProcess]
    public function testParallelRecordsCreationFailuresWithoutWaitingForever(): void
    {
        $this->atCoroutineLimit(function (): void {
            $parallel = new Parallel(concurrent: 1);
            $parallel->add(static fn (): string => 'never', 'first');
            $parallel->add(static fn (): string => 'never', 'second');

            try {
                $parallel->wait();
                $this->fail('Expected parallel execution to fail.');
            } catch (ParallelExecutionException $exception) {
                $this->assertSame(['first', 'second'], array_keys($exception->getThrowables()));
                $this->assertContainsOnlyInstancesOf(
                    CoroutineCreateException::class,
                    $exception->getThrowables(),
                );
            }
        });
    }

    #[RunInSeparateProcess]
    public function testCoroutineDriverBalancesCreationFailuresInInputOrder(): void
    {
        $this->atCoroutineLimit(function (): void {
            try {
                (new CoroutineDriver)->run([
                    'first' => static fn (): string => 'never',
                    'second' => static fn (): string => 'never',
                ]);
                $this->fail('Expected coroutine creation to fail.');
            } catch (CoroutineCreateException) {
                $this->addToAssertionCount(1);
            }
        });
    }

    #[RunInSeparateProcess]
    public function testWaiterSurfacesCreationFailureWithoutTimingOut(): void
    {
        $this->atCoroutineLimit(function (): void {
            try {
                (new Waiter(10))->wait(static fn (): string => 'never');
                $this->fail('Expected coroutine creation to fail.');
            } catch (CoroutineCreateException) {
                $this->addToAssertionCount(1);
            }
        });
    }

    #[RunInSeparateProcess]
    public function testTimerRollsBackRegistrationsWhenCreationFails(): void
    {
        $this->atCoroutineLimit(function (): void {
            foreach (['after', 'tick'] as $method) {
                $timer = new Timer;

                try {
                    $timer->{$method}(1.0, static fn (): null => null);
                    $this->fail('Expected coroutine creation to fail.');
                } catch (CoroutineCreateException) {
                    $closures = (new ReflectionProperty($timer, 'closures'))->getValue($timer);
                    $this->assertSame([], $closures);
                    $this->assertSame(['num' => 0, 'round' => 0], Timer::stats());
                }
            }
        });
    }

    #[RunInSeparateProcess]
    public function testSafeSocketResetsItsConsumerStateWhenCreationFails(): void
    {
        $this->atCoroutineLimit(function (): void {
            $socket = new SafeSocket(
                new SwooleSocket(AF_INET, SOCK_STREAM, 0),
            );

            try {
                $socket->sendAll('payload');
                $this->fail('Expected coroutine creation to fail.');
            } catch (CoroutineCreateException) {
                $this->assertFalse(
                    (new ReflectionProperty($socket, 'loop'))->getValue($socket),
                );
                $this->assertFalse(
                    (new ReflectionProperty($socket, 'channel'))
                        ->getValue($socket)
                        ->isClosing(),
                );
            } finally {
                $socket->close();
            }
        });
    }

    #[RunInSeparateProcess]
    public function testConnectionHealthChecksReturnFalseWhenProbeCreationFails(): void
    {
        $this->atCoroutineLimit(function (): void {
            $database = (new ReflectionClass(PooledConnection::class))
                ->newInstanceWithoutConstructor();
            (new ReflectionProperty(PooledConnection::class, 'connection'))
                ->setValue(
                    $database,
                    new DatabaseConnection(new PDO('sqlite::memory:')),
                );

            $redis = (new ReflectionClass(PhpRedisConnection::class))
                ->newInstanceWithoutConstructor();
            (new ReflectionProperty(RedisConnection::class, 'connection'))
                ->setValue($redis, new NativeRedis);

            $this->assertFalse($database->ping(1.0));
            $this->assertFalse($redis->heartbeatCheck(1.0));
        });
    }

    /**
     * Run a callback as the only coroutine allowed by the native runtime.
     */
    private function atCoroutineLimit(Closure $callback): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);
        SwooleCoroutine\run($callback);
    }
}
