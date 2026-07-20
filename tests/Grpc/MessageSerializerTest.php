<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Google\Protobuf\Internal\Message;
use Google\Protobuf\StringValue;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Protocol\MessageSerializer;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;
use stdClass;
use TypeError;

class MessageSerializerTest extends TestCase
{
    public function testSerializesAndDeserializesGeneratedMessages(): void
    {
        $original = (new StringValue)->setValue('hello');
        $payload = MessageSerializer::serialize($original);
        $decoded = MessageSerializer::deserialize([StringValue::class, 'decode'], $payload);

        $this->assertInstanceOf(StringValue::class, $decoded);
        $this->assertSame('hello', $decoded->getValue());
        $this->assertFalse(method_exists(StringValue::class, 'decode'));
    }

    public function testWrapsSerializationFailures(): void
    {
        $failure = new RuntimeException('encoder failed');
        $message = m::mock(Message::class);
        $message->shouldReceive('serializeToString')->once()->andThrow($failure);

        try {
            MessageSerializer::serialize($message);
            $this->fail('Expected the message serialization to fail.');
        } catch (ProtocolException $exception) {
            $this->assertSame('Unable to serialize the gRPC message.', $exception->getMessage());
            $this->assertSame($failure, $exception->getPrevious());
        }
    }

    public function testInvokesOtherCallableDeserializers(): void
    {
        MessageSerializerCallable::$called = false;

        $decoded = MessageSerializer::deserialize(
            [MessageSerializerCallable::class, 'deserialize'],
            'callable payload',
        );

        $this->assertTrue(MessageSerializerCallable::$called);
        $this->assertInstanceOf(StringValue::class, $decoded);
        $this->assertSame('callable payload', $decoded->getValue());
    }

    public function testValidatesGeneratedAndCallableDeserializersBeforeAResponseArrives(): void
    {
        MessageSerializer::validate([StringValue::class, 'decode']);
        MessageSerializer::validate([MessageSerializerCallable::class, 'deserialize']);

        $this->addToAssertionCount(2);
    }

    public function testRejectsAnInvalidGeneratedDeserializerClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The generated gRPC deserializer must name a Protocol Buffers message class.',
        );

        MessageSerializer::deserialize([stdClass::class, 'decode'], 'payload');
    }

    public function testRejectsANonCallableDeserializerArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The gRPC deserializer is not callable.');

        MessageSerializer::deserialize([MessageSerializerCallable::class, 'missing'], 'payload');
    }

    public function testRejectsANonMessageDeserializerResult(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage(
            'The gRPC deserializer must return a Protocol Buffers message.',
        );

        MessageSerializer::deserialize(static fn (string $payload): string => $payload, 'payload');
    }

    public function testWrapsProtobufParseFailuresWithoutIncludingPayloadBytes(): void
    {
        $payload = "\x0a\x05private-payload";

        try {
            MessageSerializer::deserialize([StringValue::class, 'decode'], $payload);
            $this->fail('Expected the malformed Protocol Buffers payload to fail.');
        } catch (ProtocolException $exception) {
            $this->assertSame('Unable to deserialize the gRPC message.', $exception->getMessage());
            $this->assertNotNull($exception->getPrevious());
            $this->assertStringNotContainsString('private-payload', $exception->getMessage());
        }
    }

    public function testWrapsCallableFailures(): void
    {
        $failure = new RuntimeException('decoder failed');

        try {
            MessageSerializer::deserialize(
                static function () use ($failure): Message {
                    throw $failure;
                },
                'payload',
            );
            $this->fail('Expected the callable deserializer to fail.');
        } catch (ProtocolException $exception) {
            $this->assertSame($failure, $exception->getPrevious());
        }
    }

    public function testRejectsNullMessagesThroughTheNativeType(): void
    {
        $this->expectException(TypeError::class);

        MessageSerializer::serialize(null);
    }
}

class MessageSerializerCallable
{
    public static bool $called = false;

    public static function deserialize(string $payload): Message
    {
        self::$called = true;

        return (new StringValue)->setValue($payload);
    }
}
