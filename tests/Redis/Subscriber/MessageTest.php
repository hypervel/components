<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Subscriber;

use Hypervel\Redis\Subscriber\Message;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class MessageTest extends TestCase
{
    public function testConstructWithChannelAndPayload()
    {
        $message = new Message(channel: 'my-channel', payload: 'hello');

        $this->assertSame('my-channel', $message->channel);
        $this->assertSame('hello', $message->payload);
        $this->assertNull($message->pattern);
    }

    public function testConstructWithPattern()
    {
        $message = new Message(channel: 'events.user.created', payload: 'data', pattern: 'events.*');

        $this->assertSame('events.user.created', $message->channel);
        $this->assertSame('data', $message->payload);
        $this->assertSame('events.*', $message->pattern);
    }

    public function testPropertiesAreReadonly()
    {
        $message = new Message(channel: 'ch', payload: 'msg');

        $reflection = new \ReflectionClass($message);

        $this->assertTrue($reflection->getProperty('channel')->isReadOnly());
        $this->assertTrue($reflection->getProperty('payload')->isReadOnly());
        $this->assertTrue($reflection->getProperty('pattern')->isReadOnly());
    }
}
