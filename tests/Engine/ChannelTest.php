<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Contracts\Engine\ChannelInterface;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class ChannelTest extends EngineTestCase
{
    public function testChannelPushAndPop()
    {
        $result = [
            uniqid(),
            uniqid(),
            uniqid(),
        ];
        /** @var ChannelInterface $channel */
        $channel = new Channel(3);
        foreach ($result as $value) {
            $channel->push($value);
        }

        $actual[] = $channel->pop();
        $actual[] = $channel->pop();
        $actual[] = $channel->pop();

        $this->assertSame($result, $actual);
    }

    public function testChannelInCoroutine()
    {
        $id = uniqid();
        /** @var ChannelInterface $channel */
        $channel = new Channel(1);
        Coroutine::create(function () use ($channel, $id) {
            usleep(2000);
            $channel->push($id);
        });
        $t = microtime(true);
        $this->assertSame($id, $channel->pop());
        $this->assertTrue((microtime(true) - $t) > 0.001);
    }

    public function testChannelClose()
    {
        /** @var ChannelInterface $channel */
        $channel = new Channel();
        $this->assertFalse($channel->isClosing());
        Coroutine::create(function () use ($channel) {
            usleep(1000);
            $channel->close();
        });
        $this->assertFalse($channel->pop());
        $this->assertTrue($channel->isClosing());

        $channel = new Channel(1);
        Coroutine::create(function () use ($channel) {
            $channel->close();
        });
        $this->assertTrue($channel->isClosing());
    }

    public function testChannelCloseAgain()
    {
        /** @var ChannelInterface $channel */
        $channel = new Channel(1);
        $channel->close();
        $channel->close();

        $this->assertTrue($channel->isClosing());
        $this->assertFalse($channel->isAvailable());
    }

    public function testPushClosedChannel()
    {
        /** @var ChannelInterface $channel */
        $channel = new Channel(10);
        $channel->push(111);
        $channel->close();
        $this->assertFalse($channel->isEmpty());
        $channel->push(123);
        $this->assertTrue($channel->isClosing());
        $this->assertSame(111, $channel->pop());
        $this->assertSame(false, $channel->pop());
    }

    public function testChannelIsAvailable()
    {
        /** @var ChannelInterface $channel */
        $channel = new Channel(1);
        $this->assertTrue($channel->isAvailable());
        $channel->close();
        $channel->pop();
        $this->assertFalse($channel->isAvailable());
    }

    public function testChannelTimeout()
    {
        /** @var ChannelInterface $channel */
        $channel = new Channel(1);
        $channel->pop(0.001);
        $this->assertTrue($channel->isTimeout());

        $channel->push(true);
        $channel->pop(0.001);
        $this->assertFalse($channel->isTimeout());
    }

    public function testChannelPushTimeout()
    {
        /** @var ChannelInterface $channel */
        $channel = new Channel(1);
        $this->assertSame(true, $channel->push(1, 1));
        $this->assertSame(false, $channel->push(1, 1));
        $this->assertTrue($channel->isTimeout());

        $channel = new Channel(1);
        $this->assertSame(true, $channel->push(1, 1.0));
        $this->assertSame(false, $channel->push(1, 1.0));
        $this->assertTrue($channel->isTimeout());
    }

    public function testChannelIsClosing()
    {
        /** @var ChannelInterface $channel */
        $channel = new Channel(1);
        $channel->push(true);
        $this->assertFalse($channel->isClosing());
        $this->assertFalse($channel->isTimeout());
        $this->assertTrue($channel->isAvailable());
        $channel->pop();
        $this->assertFalse($channel->isClosing());
        $this->assertFalse($channel->isTimeout());
        $this->assertTrue($channel->isAvailable());
        $channel->pop(0.001);
        $this->assertFalse($channel->isClosing());
        $this->assertTrue($channel->isTimeout());
        $this->assertTrue($channel->isAvailable());
        $this->assertTrue($channel->close());
        $this->assertTrue($channel->isClosing());
        $this->assertFalse($channel->isTimeout());
        $this->assertFalse($channel->isAvailable());
        $channel->pop();
        $this->assertTrue($channel->isClosing());
        $this->assertFalse($channel->isTimeout());
        $this->assertFalse($channel->isAvailable());
        $channel->pop(0.001);
        $this->assertTrue($channel->isClosing());
        $this->assertFalse($channel->isTimeout());
        $this->assertFalse($channel->isAvailable());
    }

    public function testSplId()
    {
        $obj = new stdClass();
        $chan = new Channel(1);
        $chan->push($obj);

        $this->assertSame(spl_object_id($obj), spl_object_id($assert = $chan->pop()));
        $this->assertSame(spl_object_hash($obj), spl_object_hash($assert));
    }
}
