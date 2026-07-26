<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Subscriber;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Engine\Channel;
use Hypervel\Redis\Subscriber\CommandBuilder;
use Hypervel\Redis\Subscriber\CommandInvoker;
use Hypervel\Redis\Subscriber\Connection;
use Hypervel\Redis\Subscriber\Exceptions\ServerException;
use Hypervel\Redis\Subscriber\Exceptions\SocketException;
use Hypervel\Redis\Subscriber\Message;
use Hypervel\Tests\Redis\Fixtures\RespServer;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Throwable;

use function Hypervel\Coroutine\go;

class CommandInvokerTest extends TestCase
{
    public function testInvokeSendsCommandAndCollectsDecodedResults(): void
    {
        $command = CommandBuilder::build(['subscribe', 'foo']);
        $server = new RespServer;
        $server->start(function ($client) use ($command): void {
            RespServer::readExact($client, strlen($command));
            fwrite($client, "*3\r\n$9\r\nsubscribe\r\n$3\r\nfoo\r\n:1\r\n");
            fread($client, 1);
        });
        $invoker = new CommandInvoker(new Connection($server->endpoint()));

        try {
            $this->assertSame(
                [['subscribe', 'foo', 1]],
                $invoker->invoke(['subscribe', 'foo'], 1),
            );
        } finally {
            $invoker->interrupt();
            $server->wait();
        }
    }

    public function testInvokeInterruptsAndPreservesSendFailure(): void
    {
        $exception = new SocketException('Connection lost');
        $connection = new ControlledConnection($exception);
        $invoker = new CommandInvoker($connection);

        try {
            $invoker->invoke(['subscribe', 'foo'], 1);
            $this->fail('Expected the send failure to be rethrown.');
        } catch (SocketException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertTrue($connection->wasClosed());
        $this->assertFalse($invoker->channel()->pop(0.01));
    }

    public function testChannelReturnsMessageChannel(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);

        try {
            $this->assertInstanceOf(Channel::class, $invoker->channel());
        } finally {
            $invoker->interrupt();
        }
    }

    public function testInterruptIsIdempotentAndClosesAllChannels(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);

        $this->assertTrue($invoker->interrupt());
        $this->assertTrue($invoker->interrupt());
        $this->assertSame(1, $connection->closeCount);
        $this->assertFalse($invoker->channel()->pop(0.01));
    }

    public function testShutdownWatcherInterruptsOnWorkerExit(): void
    {
        $connection = new BlockingConnection;

        $invoker = new CommandInvoker($connection);

        CoordinatorManager::until(Constants::WORKER_EXIT)->resume();

        usleep(50_000);

        $this->assertTrue($connection->wasClosed());
        $this->assertFalse($invoker->channel()->pop(0.01));
    }

    public function testSimplePongDoesNotPoisonTheNextAcknowledgement(): void
    {
        $ping = CommandBuilder::build('ping');
        $subscribe = CommandBuilder::build(['subscribe', 'foo']);
        $server = new RespServer;
        $server->start(function ($client) use ($ping, $subscribe): void {
            RespServer::readExact($client, strlen($ping));
            fwrite($client, "+PONG\r\n");
            RespServer::readExact($client, strlen($subscribe));
            fwrite($client, "*3\r\n$9\r\nsubscribe\r\n$3\r\nfoo\r\n:1\r\n");
            fread($client, 1);
        });
        $invoker = new CommandInvoker(new Connection($server->endpoint()));

        try {
            $this->assertSame('pong', $invoker->ping());
            $this->assertSame(
                [['subscribe', 'foo', 1]],
                $invoker->invoke(['subscribe', 'foo'], 1),
            );
        } finally {
            $invoker->interrupt();
            $server->wait();
        }
    }

    public function testArrayPongRoutesAfterSubscription(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);

        try {
            $connection->pushResponse(['subscribe', 'foo', 1]);
            $this->assertSame(
                [['subscribe', 'foo', 1]],
                $invoker->invoke(['subscribe', 'foo'], 1),
            );

            $connection->pushResponse(['pong', '']);
            $this->assertSame('pong', $invoker->ping());
        } finally {
            $invoker->interrupt();
        }
    }

    public function testAllUnsubscribeAcknowledgementAcceptsANullChannel(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);

        try {
            $connection->pushResponse(['unsubscribe', null, 0]);

            $this->assertSame(
                [['unsubscribe', null, 0]],
                $invoker->invoke(['unsubscribe'], 1),
            );
        } finally {
            $invoker->interrupt();
        }
    }

    public function testSubscribeAcknowledgementRejectsANullChannel(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);
        $connection->pushResponse(['subscribe', null, 0]);

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('malformed Redis subscribe acknowledgement');

        $invoker->invoke(['subscribe'], 1);
    }

    public function testReceiveRoutesMessagesAndPatternMessages(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);

        try {
            $connection->pushResponse(['message', 'foo', "hello\0world"]);
            $connection->pushResponse(['pmessage', 'foo.*', 'foo.bar', 'data']);

            $message = $invoker->channel()->pop(1.0);
            $patternMessage = $invoker->channel()->pop(1.0);

            $this->assertEquals(
                new Message(channel: 'foo', payload: "hello\0world"),
                $message,
            );
            $this->assertEquals(
                new Message(channel: 'foo.bar', payload: 'data', pattern: 'foo.*'),
                $patternMessage,
            );
        } finally {
            $invoker->interrupt();
        }
    }

    public function testServerFailurePropagatesToWaitingCommand(): void
    {
        $exception = new ServerException('ERR authentication failed');
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);
        $connection->pushResponse($exception);

        try {
            $invoker->invoke(['auth', 'secret'], 1);
            $this->fail('Expected the server failure to propagate.');
        } catch (ServerException $caught) {
            $this->assertSame($exception, $caught);
        }
    }

    public function testReceiveFailureClosesEveryChannelAndRemainsPrimary(): void
    {
        $exception = new SocketException('truncated response');
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);
        $connection->pushResponse($exception);

        try {
            $invoker->invoke(['subscribe', 'foo'], 1);
            $this->fail('Expected the receive failure to propagate.');
        } catch (SocketException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertTrue($connection->wasClosed());
        $this->assertFalse($invoker->channel()->pop(0.01));
    }

    public function testInvokeAfterReceiveFailureRethrowsTheCauseWithoutSending(): void
    {
        $exception = new SocketException('truncated response');
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);
        $connection->pushResponse($exception);
        $this->waitUntil(fn (): bool => $connection->wasClosed());

        try {
            $invoker->invoke(['subscribe', 'foo'], 1);
            $this->fail('Expected the receive failure to propagate.');
        } catch (SocketException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(0, $connection->sendCount);
    }

    public function testPingAfterReceiveFailureRethrowsTheCauseWithoutSending(): void
    {
        $exception = new SocketException('truncated response');
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);
        $connection->pushResponse($exception);
        $this->waitUntil(fn (): bool => $connection->wasClosed());

        try {
            $invoker->ping();
            $this->fail('Expected the receive failure to propagate.');
        } catch (SocketException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(0, $connection->sendCount);
    }

    public function testInvokeAfterCloseThrowsANamedFailureWithoutSending(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);
        $invoker->interrupt();

        try {
            $invoker->invoke(['subscribe', 'foo'], 1);
            $this->fail('Expected the closed connection failure to propagate.');
        } catch (SocketException $exception) {
            $this->assertSame('The Redis subscriber connection is closed.', $exception->getMessage());
        }

        $this->assertSame(0, $connection->sendCount);
    }

    public function testPingAfterCloseThrowsANamedFailureWithoutSending(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);
        $invoker->interrupt();

        try {
            $invoker->ping();
            $this->fail('Expected the closed connection failure to propagate.');
        } catch (SocketException $exception) {
            $this->assertSame('The Redis subscriber connection is closed.', $exception->getMessage());
        }

        $this->assertSame(0, $connection->sendCount);
    }

    public function testInvokeBlockedAcrossCloseThrowsTheChannelFailure(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);
        $result = new Channel(1);
        go(function () use ($invoker, $result): void {
            try {
                $invoker->invoke(['subscribe', 'foo'], 1);
            } catch (Throwable $exception) {
                $result->push($exception);
            }
        });

        $this->waitUntil(fn (): bool => $connection->sendCount === 1);
        $invoker->interrupt();
        $exception = $result->pop(1.0);

        $this->assertInstanceOf(SocketException::class, $exception);
        $this->assertSame(
            'The Redis subscriber command acknowledgement channel was closed.',
            $exception->getMessage(),
        );
        $this->assertSame(1, $connection->sendCount);
    }

    public function testAcknowledgementTimeoutInterruptsWhileIdleReceiveIsUnbounded(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection, timeout: 0.01);

        usleep(20_000);
        $this->assertFalse($connection->wasClosed());

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Timed out waiting');

        try {
            $invoker->invoke(['subscribe', 'foo'], 1);
        } finally {
            $this->assertTrue($connection->wasClosed());
        }
    }

    public function testPingTimeoutInterruptsTheConnection(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('PONG');

        try {
            $invoker->ping(0.01);
        } finally {
            $this->assertTrue($connection->wasClosed());
        }
    }

    public function testZeroPingTimeoutWaitsWithoutPolling(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);

        go(function () use ($connection): void {
            usleep(10_000);
            $connection->pushResponse('PONG');
        });

        try {
            $this->assertSame('pong', $invoker->ping(0));
        } finally {
            $invoker->interrupt();
        }
    }

    public function testMessageCapacityFailureLogsOnceAndRemainsPrimaryWhenLoggingFails(): void
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->andThrow(new RuntimeException('logger failed'));
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection, $logger);
        $messageChannel = m::mock(Channel::class);
        $messageChannel->shouldReceive('push')->once()->with(m::type(Message::class), 30.0)->andReturn(false);
        $messageChannel->shouldReceive('isTimeout')->once()->andReturn(true);
        $messageChannel->shouldReceive('close')->once()->andReturn(true);
        (new ReflectionProperty(CommandInvoker::class, 'messageChannel'))
            ->setValue($invoker, $messageChannel);
        $connection->pushResponse(['message', 'foo', 'payload']);

        $this->waitUntil(fn (): bool => $connection->wasClosed());

        try {
            $invoker->invoke(['subscribe', 'foo'], 1);
            $this->fail('Expected the channel-capacity failure to propagate.');
        } catch (SocketException $exception) {
            $this->assertStringContainsString('remained full for 30 seconds', $exception->getMessage());
        }
    }

    public function testConcurrentMessageChannelCloseIsTerminalWithoutLoggingCapacityFailure(): void
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldNotReceive('error');
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection, $logger);
        $messageChannel = $invoker->channel();

        for ($index = 0; $index < $messageChannel->getCapacity(); ++$index) {
            $messageChannel->push(new Message('buffer', (string) $index));
        }

        $result = new Channel(1);
        go(function () use ($invoker, $result): void {
            try {
                $invoker->invoke(['subscribe', 'foo'], 1);
            } catch (Throwable $exception) {
                $result->push($exception);
            }
        });

        $this->waitUntil(fn (): bool => $connection->sendCount === 1);

        $connection->pushResponse(['message', 'foo', 'payload']);
        $this->waitUntil(fn (): bool => $connection->receiveCount === 1);
        $messageChannel->close();

        $exception = $result->pop(1.0);

        $this->assertInstanceOf(SocketException::class, $exception);
        $this->assertSame(
            'The Redis subscriber message channel was closed.',
            $exception->getMessage(),
        );
        $this->assertTrue($connection->wasClosed());
    }

    public function testClosedResultChannelTerminatesRoutingWithoutASecondReceive(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);
        $resultChannel = (new ReflectionProperty(CommandInvoker::class, 'resultChannel'))
            ->getValue($invoker);
        $resultChannel->push('occupied');
        $connection->pushResponse(['subscribe', 'foo', 1]);
        $this->waitUntil(fn (): bool => $connection->receiveCount === 1);
        $resultChannel->close();
        $this->waitUntil(fn (): bool => $connection->wasClosed());

        try {
            $invoker->invoke(['subscribe', 'foo'], 1);
            $this->fail('Expected the result channel failure to propagate.');
        } catch (SocketException $exception) {
            $this->assertSame(
                'The Redis subscriber command acknowledgement channel was closed.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(1, $connection->receiveCount);
    }

    public function testClosedPingChannelTerminatesRoutingWithoutASecondReceive(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);
        $pingChannel = (new ReflectionProperty(CommandInvoker::class, 'pingChannel'))
            ->getValue($invoker);
        $pingChannel->push('occupied');
        $connection->pushResponse('PONG');
        $this->waitUntil(fn (): bool => $connection->receiveCount === 1);
        $pingChannel->close();
        $this->waitUntil(fn (): bool => $connection->wasClosed());

        try {
            $invoker->ping();
            $this->fail('Expected the PING channel failure to propagate.');
        } catch (SocketException $exception) {
            $this->assertSame(
                'The Redis subscriber PING channel was closed.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(1, $connection->receiveCount);
    }

    public function testMalformedResponseTerminatesTheSubscriberWithItsCause(): void
    {
        $connection = new ControlledConnection;
        $invoker = new CommandInvoker($connection);
        $connection->pushResponse(['message', 'foo', new stdClass]);

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('malformed Redis message');

        $invoker->invoke(['subscribe', 'foo'], 1);
    }

    /**
     * Wait for a test condition.
     */
    private function waitUntil(callable $condition): void
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            if ($condition()) {
                return;
            }

            usleep(1_000);
        }

        $this->fail('Timed out waiting for the test condition.');
    }
}

class BlockingConnection extends Connection
{
    private readonly Channel $gate;

    private bool $wasClosed = false;

    public function __construct()
    {
        $this->gate = new Channel(1);
    }

    public function receive(): mixed
    {
        $value = $this->gate->pop();

        if ($value === false) {
            throw new SocketException('Connection closed.');
        }

        return $value;
    }

    public function close(): void
    {
        $this->wasClosed = true;
        $this->gate->close();
    }

    public function wasClosed(): bool
    {
        return $this->wasClosed;
    }
}

class ControlledConnection extends Connection
{
    private readonly Channel $responses;

    private bool $wasClosed = false;

    public int $closeCount = 0;

    public int $receiveCount = 0;

    public int $sendCount = 0;

    public function __construct(private ?Throwable $sendFailure = null)
    {
        $this->responses = new Channel(10);
    }

    public function send(string $data): bool
    {
        ++$this->sendCount;

        if ($this->sendFailure !== null) {
            throw $this->sendFailure;
        }

        return true;
    }

    public function receive(): mixed
    {
        $response = $this->responses->pop();
        ++$this->receiveCount;

        if ($response instanceof Throwable) {
            throw $response;
        }

        if ($response === false) {
            throw new SocketException('Connection closed.');
        }

        return $response;
    }

    public function pushResponse(mixed $response): void
    {
        $this->responses->push($response);
    }

    public function close(): void
    {
        if ($this->wasClosed) {
            return;
        }

        $this->wasClosed = true;
        ++$this->closeCount;
        $this->responses->close();
    }

    public function wasClosed(): bool
    {
        return $this->wasClosed;
    }
}
