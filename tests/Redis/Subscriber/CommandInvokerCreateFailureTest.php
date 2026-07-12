<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Subscriber;

use Hypervel\Engine\Channel;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Redis\Subscriber\CommandInvoker;
use Hypervel\Redis\Subscriber\Connection;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;

class CommandInvokerCreateFailureTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testConstructionFailureInterruptsEveryOwnedResource(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);

        SwooleCoroutine\run(function (): void {
            $connection = new ConstructionFailureConnection;

            try {
                new InspectableCommandInvoker($connection);
                $this->fail('Expected coroutine creation to fail.');
            } catch (CoroutineCreateException) {
                $invoker = InspectableCommandInvoker::$constructing;

                $this->assertInstanceOf(InspectableCommandInvoker::class, $invoker);
                $this->assertTrue($connection->wasClosed);
                $this->assertNull(
                    (new ReflectionProperty(CommandInvoker::class, 'shutdownTimerId'))
                        ->getValue($invoker),
                );

                foreach (['resultChannel', 'pingChannel', 'messageChannel'] as $property) {
                    $channel = (new ReflectionProperty(CommandInvoker::class, $property))
                        ->getValue($invoker);

                    $this->assertInstanceOf(Channel::class, $channel);
                    $this->assertTrue($channel->isClosing());
                }
            } finally {
                InspectableCommandInvoker::$constructing = null;
            }
        });
    }

    #[RunInSeparateProcess]
    public function testCleanupFailureDoesNotReplaceTheConstructionFailure(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);

        SwooleCoroutine\run(function (): void {
            $connection = new ThrowingConstructionFailureConnection;

            try {
                new InspectableCommandInvoker($connection);
                $this->fail('Expected coroutine creation to fail.');
            } catch (CoroutineCreateException $exception) {
                $this->assertStringContainsString('Unable to create coroutine', $exception->getMessage());
                $this->assertTrue($connection->wasClosed);

                $invoker = InspectableCommandInvoker::$constructing;
                $this->assertInstanceOf(InspectableCommandInvoker::class, $invoker);

                foreach (['resultChannel', 'pingChannel', 'messageChannel'] as $property) {
                    $channel = (new ReflectionProperty(CommandInvoker::class, $property))
                        ->getValue($invoker);

                    $this->assertTrue($channel->isClosing());
                }
            } finally {
                InspectableCommandInvoker::$constructing = null;
            }
        });
    }
}

class InspectableCommandInvoker extends CommandInvoker
{
    public static ?self $constructing = null;

    public function __construct(Connection $connection)
    {
        static::$constructing = $this;

        parent::__construct($connection);
    }
}

class ConstructionFailureConnection extends Connection
{
    public bool $wasClosed = false;

    public function __construct()
    {
    }

    public function close(): void
    {
        $this->wasClosed = true;
    }
}

class ThrowingConstructionFailureConnection extends ConstructionFailureConnection
{
    public function close(): void
    {
        parent::close();

        throw new RuntimeException('cleanup failed');
    }
}
