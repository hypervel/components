<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server;

use Google\Protobuf\Internal\Message;
use Hypervel\Grpc\Metadata;
use LogicException;

final readonly class GrpcResponse
{
    /**
     * @param null|iterable<Message> $messages
     */
    private function __construct(
        private ?Message $message,
        private ?iterable $messages,
        private Metadata $initialMetadata,
        private Metadata $trailingMetadata,
    ) {
    }

    /**
     * Create a unary response.
     */
    public static function make(Message $message): self
    {
        return new self(
            $message,
            null,
            Metadata::make(),
            Metadata::make(),
        );
    }

    /**
     * Create a server-streaming response.
     *
     * @param iterable<Message> $messages
     */
    public static function stream(iterable $messages): self
    {
        return new self(
            null,
            $messages,
            Metadata::make(),
            Metadata::make(),
        );
    }

    /**
     * Append initial response metadata.
     *
     * @param array<string, list<string>|string>|Metadata $metadata
     */
    public function withInitialMetadata(Metadata|array $metadata): self
    {
        return new self(
            $this->message,
            $this->messages,
            $this->initialMetadata->merge($metadata),
            $this->trailingMetadata,
        );
    }

    /**
     * Append trailing response metadata.
     *
     * @param array<string, list<string>|string>|Metadata $metadata
     */
    public function withTrailingMetadata(Metadata|array $metadata): self
    {
        return new self(
            $this->message,
            $this->messages,
            $this->initialMetadata,
            $this->trailingMetadata->merge($metadata),
        );
    }

    /**
     * Determine whether this is a server-streaming response.
     *
     * @internal
     */
    public function isStreaming(): bool
    {
        return $this->messages !== null;
    }

    /**
     * Return the unary response message.
     *
     * @internal
     */
    public function message(): Message
    {
        return $this->message ?? throw new LogicException(
            'A server-streaming gRPC response does not contain a unary message.',
        );
    }

    /**
     * Return the server-streaming response messages.
     *
     * @return iterable<Message>
     *
     * @internal
     */
    public function messages(): iterable
    {
        return $this->messages ?? throw new LogicException(
            'A unary gRPC response does not contain a message stream.',
        );
    }

    /**
     * Return the initial response metadata.
     *
     * @internal
     */
    public function initialMetadata(): Metadata
    {
        return $this->initialMetadata;
    }

    /**
     * Return the trailing response metadata.
     *
     * @internal
     */
    public function trailingMetadata(): Metadata
    {
        return $this->trailingMetadata;
    }
}
