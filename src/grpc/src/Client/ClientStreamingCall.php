<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Client;

use Google\Protobuf\Internal\Message;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Protocol\FrameEncoder;

final class ClientStreamingCall extends Call
{
    /**
     * Create an active client-streaming call.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     *
     * @internal
     */
    public function __construct(
        StreamState $state,
        string $method,
        string $peer,
        array|callable $deserialize,
        Deadline $deadline,
        Connection $connection,
        FrameEncoder $requestEncoder,
        Compression $requestCompression = Compression::Identity,
    ) {
        parent::__construct($state, $method, $peer, $deserialize, $deadline);
        $this->configureWriter($connection, $requestEncoder, $requestCompression);
    }

    /**
     * Write one request message.
     */
    public function write(Message $message): void
    {
        $this->writeRequest($message);
    }

    /**
     * Finish the request stream.
     */
    public function writesDone(): void
    {
        $this->finishWrites();
    }

    /**
     * Half-close the request and wait for its unary response.
     */
    public function wait(): Message
    {
        $this->finishWrites();

        return $this->waitForUnaryResponse();
    }
}
