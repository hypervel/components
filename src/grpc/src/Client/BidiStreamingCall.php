<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Client;

use Google\Protobuf\Internal\Message;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Protocol\FrameEncoder;

final class BidiStreamingCall extends Call
{
    /**
     * Create an active bidirectional-streaming call.
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
     * Finish the request stream while response reads remain available.
     */
    public function writesDone(): void
    {
        $this->finishWrites();
    }
}
