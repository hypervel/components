<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Protocol;

use Generator;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\StatusCode;

/**
 * @internal
 */
final class FrameDecoder
{
    private string $buffer = '';

    private int $offset = 0;

    private ?int $frameLength = null;

    private ?int $compressedFlag = null;

    public function __construct(
        private readonly Compression $encoding,
        private readonly int $maxMessageSize,
    ) {
    }

    /**
     * Add bytes and yield each complete gRPC message payload.
     *
     * @return Generator<int, string>
     */
    public function push(string $bytes): Generator
    {
        $this->buffer .= $bytes;

        while (true) {
            if ($this->frameLength === null) {
                if ($this->remainingBytes() < 5) {
                    break;
                }

                $header = unpack('Cflag/Nlength', $this->buffer, $this->offset);

                if ($header === false) {
                    throw new ProtocolException('Unable to decode the gRPC frame header.');
                }

                if ($header['flag'] !== 0 && $header['flag'] !== 1) {
                    throw new ProtocolException('The gRPC compressed flag must be 0 or 1.');
                }

                if ($header['flag'] === 1 && $this->encoding === Compression::Identity) {
                    throw new ProtocolException(
                        'A compressed gRPC frame was received without a negotiated encoding.',
                    );
                }

                if ($header['length'] > $this->maxMessageSize) {
                    throw new RpcException(
                        StatusCode::ResourceExhausted,
                        'The inbound gRPC message exceeds the configured limit.',
                    );
                }

                $this->compressedFlag = $header['flag'];
                $this->frameLength = $header['length'];
                $this->offset += 5;
            }

            if ($this->remainingBytes() < $this->frameLength) {
                break;
            }

            $payload = substr($this->buffer, $this->offset, $this->frameLength);
            $compressed = $this->compressedFlag === 1;
            $this->offset += $this->frameLength;
            $this->frameLength = null;
            $this->compressedFlag = null;

            yield $compressed ? $this->decompress($payload) : $payload;
        }

        if ($this->offset !== 0) {
            $this->buffer = substr($this->buffer, $this->offset);
            $this->offset = 0;
        }
    }

    /**
     * Verify that the message stream ended on a frame boundary.
     */
    public function finish(): void
    {
        if ($this->frameLength !== null || $this->remainingBytes() !== 0) {
            throw new ProtocolException('The gRPC message stream ended with an incomplete frame.');
        }

        $this->buffer = '';
        $this->offset = 0;
    }

    /**
     * Return the unread byte count.
     */
    private function remainingBytes(): int
    {
        return strlen($this->buffer) - $this->offset;
    }

    /**
     * Decompress one bounded gzip message.
     */
    private function decompress(string $payload): string
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);

        if ($context === false) {
            throw new ProtocolException('Unable to initialize gRPC message decompression.');
        }

        $output = '';
        $payloadLength = strlen($payload);
        $chunkSize = max(64, intdiv($this->maxMessageSize, 1032));
        $payloadOffset = 0;

        do {
            $length = min($chunkSize, $payloadLength - $payloadOffset);
            $flush = $payloadOffset + $length === $payloadLength
                ? ZLIB_FINISH
                : ZLIB_NO_FLUSH;
            $decoded = @inflate_add(
                $context,
                substr($payload, $payloadOffset, $length),
                $flush,
            );

            if ($decoded === false) {
                throw new ProtocolException('The compressed gRPC message is invalid.');
            }

            if (strlen($decoded) > $this->maxMessageSize - strlen($output)) {
                throw new RpcException(
                    StatusCode::ResourceExhausted,
                    'The decompressed gRPC message exceeds the configured limit.',
                );
            }

            $output .= $decoded;
            $payloadOffset += $length;
        } while ($payloadOffset < $payloadLength);

        if (
            inflate_get_status($context) !== ZLIB_STREAM_END
            || inflate_get_read_len($context) !== $payloadLength
        ) {
            throw new ProtocolException('The compressed gRPC message is incomplete or has trailing data.');
        }

        return $output;
    }
}
