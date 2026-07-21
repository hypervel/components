<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Client;

use Closure;
use Google\Protobuf\Internal\Message;
use Hypervel\Grpc\Protocol\Deadline;

final class ServerStreamingCall extends Call
{
    /**
     * Create an active server-streaming call.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     * @param null|Closure(int): StreamState $attemptFactory
     *
     * @internal
     */
    public function __construct(
        StreamState $state,
        string $method,
        string $peer,
        array|callable $deserialize,
        Deadline $deadline,
        ?RetryPolicy $retryPolicy = null,
        ?Closure $attemptFactory = null,
        ?RetryBackoff $retryBackoff = null,
    ) {
        parent::__construct(
            $state,
            $method,
            $peer,
            $deserialize,
            $deadline,
            $retryPolicy,
            $attemptFactory,
            $retryBackoff,
        );
    }

    /**
     * Read the next response message or return null after a clean end.
     */
    public function read(): ?Message
    {
        $this->beginReading();

        try {
            return $this->readResponse();
        } finally {
            $this->endReading();
        }
    }

    /**
     * Yield every response message until the server completes the stream.
     *
     * @return iterable<Message>
     */
    public function responses(): iterable
    {
        $this->beginReading();

        try {
            while (($message = $this->readResponse()) !== null) {
                yield $message;
            }
        } finally {
            $this->endReading();
        }
    }
}
