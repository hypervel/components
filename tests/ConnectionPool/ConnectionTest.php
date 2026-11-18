<?php

declare(strict_types=1);

namespace Hypervel\Tests\ConnectionPool;

use Closure;
use Hypervel\ConnectionPool\Connection;
use Hypervel\ConnectionPool\ConnectionPool;
use Hypervel\ConnectionPool\Events\ConnectionReleasing;
use Hypervel\ConnectionPool\PoolOptions;
use Hypervel\Container\Container;
use Hypervel\Contracts\ConnectionPool\ConnectionPool as ConnectionPoolContract;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Support\Testing\Fakes\EventFake;
use Hypervel\Tests\ConnectionPool\Fixtures\ActiveConnectionStub;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;
use TypeError;

class ConnectionTest extends TestCase
{
    use VerifiesDoubles;

    public function testGetConnectionReturnsTheActiveConnection(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('warning')->never();
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(true);
        $container->expects('make')->with(StdoutLoggerInterface::class)->returns($logger);
        $container->allows('bound')->with('events')->returns(false);

        $connection = new ActiveConnectionStub($container->instance(), Double::for(ConnectionPool::class)->strict());
        $this->assertSame($connection, $connection->getConnection());
    }

    #[DataProvider('acquisitionFailures')]
    public function testGetConnectionDoesNotRetryFailure(string $exceptionClass): void
    {
        $failure = new $exceptionClass('acquisition failed');
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('warning')->never();
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(true);
        $container->expects('make')->with(StdoutLoggerInterface::class)->returns($logger);
        $container->allows('bound')->with('events')->returns(false);
        $connection = new ConnectionCallbackStub(
            $container->instance(),
            Double::for(ConnectionPool::class)->strict(),
            static fn (): mixed => throw $failure,
        );
        $caught = null;

        try {
            $connection->getConnection();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        $this->assertSame(1, $connection->getActiveConnectionCalls);
    }

    public static function acquisitionFailures(): array
    {
        return [
            'ordinary failure' => [RuntimeException::class],
            'programming error' => [TypeError::class],
        ];
    }

    public function testGetConnectionDoesNotRetryCancellation(): void
    {
        $cancellation = new CanceledException('connection canceled');
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('warning')->never();
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(true);
        $container->expects('make')->with(StdoutLoggerInterface::class)->returns($logger);
        $container->allows('bound')->with('events')->returns(false);
        $connection = new ConnectionCallbackStub(
            $container->instance(),
            Double::for(ConnectionPool::class)->strict(),
            static fn (): mixed => throw $cancellation,
        );

        try {
            $connection->getConnection();
            $this->fail('Getting the connection was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(1, $connection->getActiveConnectionCalls);
    }

    public function testConnectionReleasingEvent(): void
    {
        $assert = 0;
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(false);
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($dispatcher = Double::for(Dispatcher::class)->strict());
        $dispatcher->expects('hasListeners')->with(ConnectionReleasing::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(ConnectionReleasing::class))->resolves(function (ConnectionReleasing $event) use (&$assert) {
            $assert = $event->connection->getLastReleaseTime();
        });

        $connection = new ActiveConnectionStub($container->instance(), $pool = Double::for(ConnectionPool::class)->strict());
        $pool->allows('release')->returns(null);
        $pool->allows('getOptions')->returns(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));

        $before = hrtime(true) / 1e9;
        $connection->release();
        $after = hrtime(true) / 1e9;

        $this->assertGreaterThanOrEqual($before, $assert);
        $this->assertLessThanOrEqual($after, $assert);
    }

    public function testReleaseUsesTheCurrentEventDispatcher(): void
    {
        $container = new Container;
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->allows('hasListeners')->with(ConnectionReleasing::class)->returns(true);
        $dispatcher->expects('dispatch')->never();
        $container->instance('events', $dispatcher);

        $pool = Double::for(ConnectionPool::class)->strict();
        $pool->expects('getOptions')->returns(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $connection = new ActiveConnectionStub($container, $pool);
        $pool->expects('release')->with(Argument::same($connection));

        $fake = new EventFake($dispatcher, [ConnectionReleasing::class]);
        $container->instance('events', $fake);
        $connection->release();

        $fake->assertDispatched(ConnectionReleasing::class, fn (ConnectionReleasing $event): bool => $event->connection === $connection);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testReleaseListenerCancellationStillReturnsTheConnectionOnce(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('listener canceled');
        $failure = $cleanupThrows ? new RuntimeException('Listener cleanup failed.', previous: $cancellation) : $cancellation;
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(false);
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($dispatcher = Double::for(Dispatcher::class)->strict());
        $dispatcher->expects('hasListeners')->with(ConnectionReleasing::class)->returns(true);
        $dispatcher->expects('dispatch')->throws($failure);
        $connection = new ActiveConnectionStub($container->instance(), $pool = Double::for(ConnectionPool::class)->strict());
        $pool->expects('getOptions')->returns(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->expects('release')->with(Argument::same($connection));

        try {
            $connection->release();
            $this->fail('The release listener was expected to be canceled.');
        } catch (Throwable $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testReleaseListenerCancellationRemainsPrimaryOverCleanupCancellation(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('listener canceled');
        $failure = $cleanupThrows ? new RuntimeException('Listener cleanup failed.', previous: $cancellation) : $cancellation;
        $cleanupCancellation = new CanceledException('release canceled');
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(false);
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($dispatcher = Double::for(Dispatcher::class)->strict());
        $dispatcher->expects('hasListeners')->with(ConnectionReleasing::class)->returns(true);
        $dispatcher->expects('dispatch')->throws($failure);
        $connection = new ActiveConnectionStub($container->instance(), $pool = Double::for(ConnectionPool::class)->strict());
        $pool->expects('getOptions')->returns(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->expects('release')->with(Argument::same($connection))->throws($cleanupCancellation);

        try {
            $connection->release();
            $this->fail('The release listener was expected to be canceled.');
        } catch (Throwable $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testCancellationWhileLoggingAListenerFailureStillReturnsTheConnectionOnce(): void
    {
        $cancellation = new CanceledException('logging canceled');
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('error')->throws($cancellation);
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(true);
        $container->expects('make')->with(StdoutLoggerInterface::class)->returns($logger);
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($dispatcher = Double::for(Dispatcher::class)->strict());
        $dispatcher->expects('hasListeners')->with(ConnectionReleasing::class)->returns(true);
        $dispatcher->expects('dispatch')->throws(new RuntimeException('listener failed'));
        $connection = new ActiveConnectionStub($container->instance(), $pool = Double::for(ConnectionPool::class)->strict());
        $pool->expects('getOptions')->returns(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->expects('release')->with(Argument::same($connection));

        try {
            $connection->release();
            $this->fail('Logging the listener failure was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testOrdinaryReleaseListenerFailureIsLoggedAndTheConnectionIsReturned(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('error')
            ->with(Argument::satisfies(static fn (string $message): bool => str_contains($message, 'listener failed')));
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(true);
        $container->expects('make')->with(StdoutLoggerInterface::class)->returns($logger);
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($dispatcher = Double::for(Dispatcher::class)->strict());
        $dispatcher->expects('hasListeners')->with(ConnectionReleasing::class)->returns(true);
        $dispatcher->expects('dispatch')->throws(new RuntimeException('listener failed'));
        $connection = new ActiveConnectionStub($container->instance(), $pool = Double::for(ConnectionPool::class)->strict());
        $pool->expects('getOptions')->returns(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->expects('release')->with(Argument::same($connection));

        $connection->release();
    }

    #[DataProvider('cleanupFailures')]
    public function testLoggerFailureStillReleasesTheConnectionAndPreservesFailurePrecedence(?string $cleanupExceptionClass): void
    {
        $loggingFailure = new RuntimeException('logger failed');
        $cleanupFailure = $cleanupExceptionClass === null ? null : new $cleanupExceptionClass('cleanup failed');
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('error')->throws($loggingFailure);
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(true);
        $container->expects('make')->with(StdoutLoggerInterface::class)->returns($logger);
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($dispatcher = Double::for(Dispatcher::class)->strict());
        $dispatcher->expects('hasListeners')->with(ConnectionReleasing::class)->returns(true);
        $dispatcher->expects('dispatch')->throws(new RuntimeException('listener failed'));
        $connection = new ActiveConnectionStub($container->instance(), $pool = Double::for(ConnectionPool::class)->strict());
        $pool->expects('getOptions')->returns(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->expects('release')->with(Argument::same($connection))->resolves(static function () use ($cleanupFailure): void {
            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        });
        $caught = null;

        try {
            $connection->release();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($cleanupFailure ?? $loggingFailure, $caught);
    }

    public static function cleanupFailures(): array
    {
        return [
            'successful cleanup' => [null],
            'ordinary cleanup failure' => [RuntimeException::class],
            'canceled cleanup' => [CanceledException::class],
        ];
    }

    #[DataProvider('secondaryReportingFailures')]
    public function testReleaseCancellationSurvivesCleanupAndReportingFailures(string $reportingExceptionClass): void
    {
        $cancellation = new CanceledException('listener canceled');
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('error')->throws(new $reportingExceptionClass('secondary reporting failed'));
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(true);
        $container->expects('make')->with(StdoutLoggerInterface::class)->returns($logger);
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($dispatcher = Double::for(Dispatcher::class)->strict());
        $dispatcher->expects('hasListeners')->with(ConnectionReleasing::class)->returns(true);
        $dispatcher->expects('dispatch')->throws($cancellation);
        $connection = new ActiveConnectionStub($container->instance(), $pool = Double::for(ConnectionPool::class)->strict());
        $pool->expects('getOptions')->returns(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->expects('release')->with(Argument::same($connection))->throws(new RuntimeException('cleanup failed'));
        $caught = null;

        try {
            $connection->release();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($cancellation, $caught);
    }

    public static function secondaryReportingFailures(): array
    {
        return [
            'ordinary reporting failure' => [RuntimeException::class],
            'canceled reporting' => [CanceledException::class],
        ];
    }

    public function testConfiguredReleaseEventIsNotDispatchedWithoutListeners(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(false);
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($dispatcher = Double::for(Dispatcher::class)->strict());
        $dispatcher->expects('hasListeners')->with(ConnectionReleasing::class)->returns(false);
        $dispatcher->expects('dispatch')->never();
        $connection = new ActiveConnectionStub($container->instance(), $pool = Double::for(ConnectionPool::class)->strict());
        $pool->expects('getOptions')->returns(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->expects('release')->with(Argument::same($connection));

        $connection->release();
    }

    public function testDontHaveEvents(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(false);
        $container->expects('bound')->never()->with('events');
        $container->expects('make')->never()->with('events');

        $connection = new ActiveConnectionStub($container->instance(), $pool = Double::for(ConnectionPool::class)->strict());
        $pool->allows('release')->returns(null);
        $pool->allows('getOptions')->returns(PoolOptions::fromArray([]));

        $connection->release();
    }

    public function testDiscardDelegatesToOwningPool(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(false);
        $container->allows('bound')->with('events')->returns(false);
        $pool = Double::for(ConnectionPool::class)->strict();
        $connection = new ActiveConnectionStub($container->instance(), $pool);
        $pool->expects('discard')->with(Argument::same($connection));

        $connection->discard();
    }

    public function testCheckDoesNotResetActivityTimestamp(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(false);
        $container->allows('bound')->with('events')->returns(false);
        $pool = Double::for(ConnectionPool::class)->strict();
        $pool->expects('release');
        $pool->expects('getOptions')->times(2)->returns(PoolOptions::fromArray(['max_idle_time' => 60.0]));
        $connection = new ActiveConnectionStub($container->instance(), $pool);

        $connection->release();
        $lastUseTime = $connection->getLastUseTime();

        $this->assertTrue($connection->check());
        $this->assertSame($lastUseTime, $connection->getLastUseTime());
    }

    public function testNullIdleTimeoutKeepsAnUnusedConnectionValid(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('has')->with(StdoutLoggerInterface::class)->returns(false);
        $container->allows('bound')->with('events')->returns(false);
        $pool = Double::for(ConnectionPool::class)->strict();
        $pool->expects('getOptions')->returns(PoolOptions::fromArray(['max_idle_time' => null]));
        $connection = new ActiveConnectionStub($container->instance(), $pool);

        $this->assertTrue($connection->check());
        $this->assertSame(0.0, $connection->getLastUseTime());
        $this->assertSame(0.0, $connection->getLastReleaseTime());
    }
}

class ConnectionCallbackStub extends Connection
{
    public int $getActiveConnectionCalls = 0;

    public function __construct(
        ContainerContract $container,
        ConnectionPoolContract $pool,
        protected Closure $getActiveConnectionCallback,
    ) {
        parent::__construct($container, $pool);
    }

    public function getActiveConnection(): mixed
    {
        ++$this->getActiveConnectionCalls;

        return ($this->getActiveConnectionCallback)();
    }

    public function reconnect(): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }
}
