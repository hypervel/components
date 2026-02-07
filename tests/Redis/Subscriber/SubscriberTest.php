<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Subscriber;

use Hypervel\Engine\Channel;
use Hypervel\Redis\Subscriber\CommandInvoker;
use Hypervel\Redis\Subscriber\Exceptions\SubscribeException;
use Hypervel\Redis\Subscriber\Exceptions\UnsubscribeException;
use Hypervel\Redis\Subscriber\Subscriber;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionClass;

/**
 * @internal
 * @coversNothing
 */
class SubscriberTest extends TestCase
{
    public function testSubscribeDelegatesToCommandInvoker()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('invoke')
            ->once()
            ->with(['subscribe', 'foo', 'bar'], 2)
            ->andReturn([['subscribe'], ['subscribe']]);

        $subscriber = $this->createSubscriber($invoker);
        $subscriber->subscribe('foo', 'bar');
    }

    public function testSubscribePrependsPrefix()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('invoke')
            ->once()
            ->with(['subscribe', 'app:foo', 'app:bar'], 2)
            ->andReturn([['subscribe'], ['subscribe']]);

        $subscriber = $this->createSubscriber($invoker, prefix: 'app:');
        $subscriber->subscribe('foo', 'bar');
    }

    public function testSubscribeThrowsOnFailure()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('invoke')
            ->once()
            ->with(['subscribe', 'foo'], 1)
            ->andReturn([false]);
        $invoker->shouldReceive('interrupt')->once();

        $subscriber = $this->createSubscriber($invoker);

        $this->expectException(SubscribeException::class);
        $this->expectExceptionMessage('Subscribe failed');

        $subscriber->subscribe('foo');
    }

    public function testUnsubscribeDelegatesToCommandInvoker()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('invoke')
            ->once()
            ->with(['unsubscribe', 'foo'], 1)
            ->andReturn([['unsubscribe']]);

        $subscriber = $this->createSubscriber($invoker);
        $subscriber->unsubscribe('foo');
    }

    public function testUnsubscribePrependsPrefix()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('invoke')
            ->once()
            ->with(['unsubscribe', 'app:foo'], 1)
            ->andReturn([['unsubscribe']]);

        $subscriber = $this->createSubscriber($invoker, prefix: 'app:');
        $subscriber->unsubscribe('foo');
    }

    public function testUnsubscribeThrowsOnFailure()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('invoke')
            ->once()
            ->with(['unsubscribe', 'foo'], 1)
            ->andReturn([false]);
        $invoker->shouldReceive('interrupt')->once();

        $subscriber = $this->createSubscriber($invoker);

        $this->expectException(UnsubscribeException::class);
        $this->expectExceptionMessage('Unsubscribe failed');

        $subscriber->unsubscribe('foo');
    }

    public function testPsubscribeDelegatesToCommandInvoker()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('invoke')
            ->once()
            ->with(['psubscribe', 'foo.*', 'bar.*'], 2)
            ->andReturn([['psubscribe'], ['psubscribe']]);

        $subscriber = $this->createSubscriber($invoker);
        $subscriber->psubscribe('foo.*', 'bar.*');
    }

    public function testPsubscribePrependsPrefix()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('invoke')
            ->once()
            ->with(['psubscribe', 'app:events.*'], 1)
            ->andReturn([['psubscribe']]);

        $subscriber = $this->createSubscriber($invoker, prefix: 'app:');
        $subscriber->psubscribe('events.*');
    }

    public function testPsubscribeThrowsOnFailure()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('invoke')
            ->once()
            ->with(['psubscribe', 'foo.*'], 1)
            ->andReturn([false]);
        $invoker->shouldReceive('interrupt')->once();

        $subscriber = $this->createSubscriber($invoker);

        $this->expectException(SubscribeException::class);
        $this->expectExceptionMessage('Psubscribe failed');

        $subscriber->psubscribe('foo.*');
    }

    public function testPunsubscribeDelegatesToCommandInvoker()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('invoke')
            ->once()
            ->with(['punsubscribe', 'foo.*'], 1)
            ->andReturn([['punsubscribe']]);

        $subscriber = $this->createSubscriber($invoker);
        $subscriber->punsubscribe('foo.*');
    }

    public function testPunsubscribePrependsPrefix()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('invoke')
            ->once()
            ->with(['punsubscribe', 'app:foo.*'], 1)
            ->andReturn([['punsubscribe']]);

        $subscriber = $this->createSubscriber($invoker, prefix: 'app:');
        $subscriber->punsubscribe('foo.*');
    }

    public function testPunsubscribeThrowsOnFailure()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('invoke')
            ->once()
            ->with(['punsubscribe', 'foo.*'], 1)
            ->andReturn([false]);
        $invoker->shouldReceive('interrupt')->once();

        $subscriber = $this->createSubscriber($invoker);

        $this->expectException(UnsubscribeException::class);
        $this->expectExceptionMessage('Punsubscribe failed');

        $subscriber->punsubscribe('foo.*');
    }

    public function testChannelDelegatesToCommandInvoker()
    {
        $channel = new Channel(1);
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('channel')->once()->andReturn($channel);

        $subscriber = $this->createSubscriber($invoker);

        $this->assertSame($channel, $subscriber->channel());
    }

    public function testCloseSetsClosedAndInterrupts()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('interrupt')->once()->andReturn(true);

        $subscriber = $this->createSubscriber($invoker);

        $this->assertFalse($subscriber->closed);

        $subscriber->close();

        $this->assertTrue($subscriber->closed);
    }

    public function testPingDelegatesToCommandInvoker()
    {
        $invoker = m::mock(CommandInvoker::class);
        $invoker->shouldReceive('ping')->once()->with(2.5)->andReturn('pong');

        $subscriber = $this->createSubscriber($invoker);

        $this->assertSame('pong', $subscriber->ping(2.5));
    }

    /**
     * Create a Subscriber with a mock CommandInvoker, bypassing the real connection.
     */
    private function createSubscriber(CommandInvoker $invoker, string $prefix = ''): Subscriber
    {
        $reflection = new ReflectionClass(Subscriber::class);
        $subscriber = $reflection->newInstanceWithoutConstructor();

        $subscriber->host = '127.0.0.1';
        $subscriber->port = 6379;
        $subscriber->password = '';
        $subscriber->timeout = 5.0;
        $subscriber->prefix = $prefix;
        $subscriber->closed = false;

        $reflection->getProperty('commandInvoker')->setValue($subscriber, $invoker);

        return $subscriber;
    }
}
