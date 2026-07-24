<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Protocol;

use Google\Protobuf\Internal\Message;
use Hypervel\Grpc\Exceptions\ProtocolException;
use InvalidArgumentException;
use Throwable;

/**
 * @internal
 */
class MessageSerializer
{
    /**
     * Serialize a Protocol Buffers message.
     */
    public static function serialize(Message $message): string
    {
        try {
            return $message->serializeToString();
        } catch (Throwable $throwable) {
            throw new ProtocolException(
                'Unable to serialize the gRPC message.',
                previous: $throwable,
            );
        }
    }

    /**
     * Validate a generated-style or callable response deserializer.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     */
    public static function validate(array|callable $deserialize): void
    {
        if (
            is_array($deserialize)
            && count($deserialize) === 2
            && ($deserialize[1] ?? null) === 'decode'
        ) {
            $messageClass = $deserialize[0] ?? null;

            if (! is_string($messageClass) || ! is_a($messageClass, Message::class, true)) {
                throw new InvalidArgumentException(
                    'The generated gRPC deserializer must name a Protocol Buffers message class.',
                );
            }

            return;
        }

        if (! is_callable($deserialize)) {
            throw new InvalidArgumentException('The gRPC deserializer is not callable.');
        }
    }

    /**
     * Deserialize a Protocol Buffers message.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     */
    public static function deserialize(array|callable $deserialize, string $payload): Message
    {
        self::validate($deserialize);

        if (
            is_array($deserialize)
            && count($deserialize) === 2
            && ($deserialize[1] ?? null) === 'decode'
        ) {
            $messageClass = $deserialize[0];

            try {
                $message = new $messageClass;
                $message->mergeFromString($payload);
            } catch (Throwable $throwable) {
                throw new ProtocolException(
                    'Unable to deserialize the gRPC message.',
                    previous: $throwable,
                );
            }

            return $message;
        }

        try {
            $message = $deserialize($payload);
        } catch (Throwable $throwable) {
            throw new ProtocolException(
                'Unable to deserialize the gRPC message.',
                previous: $throwable,
            );
        }

        if (! $message instanceof Message) {
            throw new ProtocolException(
                'The gRPC deserializer must return a Protocol Buffers message.',
            );
        }

        return $message;
    }
}
