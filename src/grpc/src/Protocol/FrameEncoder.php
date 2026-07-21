<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Protocol;

use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\StatusCode;

/**
 * @internal
 */
final readonly class FrameEncoder
{
    public function __construct(private int $maxMessageSize)
    {
    }

    /**
     * Encode one payload as a length-prefixed gRPC message.
     */
    public function encode(
        string $payload,
        Compression $compression = Compression::Identity,
    ): string {
        if (strlen($payload) > $this->maxMessageSize) {
            throw new RpcException(
                StatusCode::ResourceExhausted,
                'The outbound gRPC message exceeds the configured limit.',
            );
        }

        $wirePayload = $compression === Compression::Gzip
            ? gzencode($payload, -1, ZLIB_ENCODING_GZIP)
            : $payload;

        if ($wirePayload === false) {
            throw new ProtocolException('Unable to compress the gRPC message.');
        }

        $wireLength = strlen($wirePayload);

        if ($wireLength > $this->maxMessageSize || $wireLength > 0xFFFFFFFF) {
            throw new RpcException(
                StatusCode::ResourceExhausted,
                'The encoded gRPC message exceeds the configured limit.',
            );
        }

        return pack('CN', $compression === Compression::Identity ? 0 : 1, $wireLength)
            . $wirePayload;
    }
}
