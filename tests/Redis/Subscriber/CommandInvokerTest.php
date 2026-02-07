<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Subscriber;

use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\Subscriber\CommandInvoker;
use Hypervel\Redis\Subscriber\Connection;
use Hypervel\Redis\Subscriber\Message;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class CommandInvokerTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function setUp(): void
    {
        parent::setUp();

        // RunTestsInCoroutine resumes the WORKER_EXIT coordinator after each test,
        // closing its channel. Clear it so each test gets a fresh coordinator.
        CoordinatorManager::clear(Constants::WORKER_EXIT);
    }

    public function testInvokeSendsCommandAndCollectsResults()
    {
        // Simulate a subscribe confirmation response:
        // *3\r\n $9\r\n subscribe\r\n $3\r\n foo\r\n :1\r\n
        $responses = [
            "*3\r\n",
            "\$9\r\n",
            "subscribe\r\n",
            "\$3\r\n",
            "foo\r\n",
            ":1\r\n",
            // After the subscribe response, return false to end the loop
            false,
        ];

        $connection = $this->createMockConnection($responses);
        $connection->shouldReceive('send')->once();
        $connection->shouldReceive('close')->atLeast()->once();

        $invoker = new CommandInvoker($connection);
        $result = $invoker->invoke(['subscribe', 'foo'], 1);

        $this->assertCount(1, $result);
        $this->assertIsArray($result[0]);
    }

    public function testChannelReturnsMessageChannel()
    {
        $connection = $this->createMockConnection([false]);
        $connection->shouldReceive('close')->once();

        $invoker = new CommandInvoker($connection);
        $channel = $invoker->channel();

        $this->assertInstanceOf(\Hypervel\Engine\Channel::class, $channel);
    }

    public function testInterruptClosesAllChannels()
    {
        $connection = $this->createMockConnection([false]);
        $connection->shouldReceive('close')->twice(); // Once from recv=false, once from explicit interrupt

        $invoker = new CommandInvoker($connection);

        // Give the background coroutine time to start and exit
        usleep(10_000);

        $result = $invoker->interrupt();
        $this->assertTrue($result);

        // Channel should be closed — pop returns false
        $this->assertFalse($invoker->channel()->pop(0.01));
    }

    public function testReceiveRoutesMessageToMessageChannel()
    {
        // Simulate: subscribe confirmation, then a message, then disconnect
        $responses = [
            // Subscribe confirmation (*3 array)
            "*3\r\n",
            "\$9\r\n",
            "subscribe\r\n",
            "\$3\r\n",
            "foo\r\n",
            ":1\r\n",
            // Message (*3 array with 'message' type — 7 lines total)
            "*3\r\n",
            "\$7\r\n",
            "message\r\n",
            "\$3\r\n",
            "foo\r\n",
            "\$5\r\n",
            "hello\r\n",
            // Disconnect
            false,
        ];

        $connection = $this->createMockConnection($responses);
        $connection->shouldReceive('send')->once();
        $connection->shouldReceive('close')->atLeast()->once();

        $invoker = new CommandInvoker($connection);

        // Send subscribe to consume the confirmation
        $invoker->invoke(['subscribe', 'foo'], 1);

        // Pop the message from the message channel
        $message = $invoker->channel()->pop(1.0);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('foo', $message->channel);
        $this->assertSame('hello', $message->payload);
        $this->assertNull($message->pattern);
    }

    public function testReceiveRoutesPmessageToMessageChannel()
    {
        // Simulate: psubscribe confirmation, then a pmessage, then disconnect
        $responses = [
            // Psubscribe confirmation (*3 array)
            "*3\r\n",
            "\$10\r\n",
            "psubscribe\r\n",
            "\$5\r\n",
            "foo.*\r\n",
            ":1\r\n",
            // Pmessage (*4 array with 'pmessage' type — 9 lines total)
            "*4\r\n",
            "\$8\r\n",
            "pmessage\r\n",
            "\$5\r\n",
            "foo.*\r\n",
            "\$7\r\n",
            "foo.bar\r\n",
            "\$4\r\n",
            "data\r\n",
            // Disconnect
            false,
        ];

        $connection = $this->createMockConnection($responses);
        $connection->shouldReceive('send')->once();
        $connection->shouldReceive('close')->atLeast()->once();

        $invoker = new CommandInvoker($connection);

        // Send psubscribe to consume the confirmation
        $invoker->invoke(['psubscribe', 'foo.*'], 1);

        // Pop the pmessage from the message channel
        $message = $invoker->channel()->pop(1.0);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('foo.bar', $message->channel);
        $this->assertSame('data', $message->payload);
        $this->assertSame('foo.*', $message->pattern);
    }

    public function testReceiveRoutesPongToPingChannel()
    {
        // Simulate: pong response, then disconnect
        $responses = [
            // Pong (*1 array — 5 lines: *1, $4, pong, $0, empty)
            // Actually looking at the code: type = buffer[2], pong check is count==5
            // So it needs: *-something, $4, pong, $0, (empty)
            "*1\r\n",
            "\$4\r\n",
            "pong\r\n",
            "\$0\r\n",
            "\r\n",
            // Disconnect
            false,
        ];

        $connection = $this->createMockConnection($responses);
        $connection->shouldReceive('send')->once();
        $connection->shouldReceive('close')->once();

        $invoker = new CommandInvoker($connection);

        // Send ping — the result should come from the ping channel
        $result = $invoker->ping(1.0);

        $this->assertSame('pong', $result);
    }

    public function testReceiveDisconnectsOnEmptyLine()
    {
        $connection = $this->createMockConnection(['']);
        $connection->shouldReceive('close')->once();

        $invoker = new CommandInvoker($connection);

        // Give the background coroutine time to process
        usleep(10_000);

        // Message channel should be closed
        $this->assertFalse($invoker->channel()->pop(0.01));
    }

    /**
     * Create a mock Connection that returns the given responses from recv().
     *
     * Uses andReturnUsing with usleep before the final false response so the
     * background coroutine yields, giving the test coroutine time to pop
     * messages from the channel before interrupt() closes it.
     *
     * @param array<string|false> $responses
     */
    private function createMockConnection(array $responses): Connection
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('recv')
            ->andReturnUsing(function () use (&$responses) {
                $response = array_shift($responses);
                if ($response === false || $response === null) {
                    // Yield before disconnecting so the test coroutine
                    // can pop any buffered messages from the channel.
                    usleep(50_000);
                    return false;
                }
                return $response;
            });

        return $connection;
    }
}
