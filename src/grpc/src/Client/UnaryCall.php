<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Client;

use Closure;
use Google\Protobuf\Internal\Message;
use Hypervel\Grpc\Protocol\Deadline;

final class UnaryCall extends Call
{
    /**
     * Create an active unary call.
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
     * Wait for and return the unary response message.
     */
    public function wait(): Message
    {
        return $this->waitForUnaryResponse();
    }
}
