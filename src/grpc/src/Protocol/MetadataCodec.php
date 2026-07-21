<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Protocol;

use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Metadata;
use InvalidArgumentException;

/**
 * @internal
 */
class MetadataCodec
{
    /**
     * Encode application metadata as transport header values.
     *
     * @return array<string, string>
     */
    public static function encode(Metadata $metadata): array
    {
        $headers = [];

        foreach ($metadata as $key => $values) {
            $wireValues = [];

            foreach ($values as $value) {
                $wireValues[] = str_ends_with($key, '-bin')
                    ? rtrim(base64_encode($value), '=')
                    : $value;
            }

            $headers[$key] = implode(',', $wireValues);
        }

        return $headers;
    }

    /**
     * Decode transport headers into application metadata.
     *
     * @param array<array-key, list<string>|string> $headers
     */
    public static function decode(array $headers): Metadata
    {
        $metadata = [];

        foreach ($headers as $key => $wireValues) {
            if (! is_string($key)) {
                throw new ProtocolException('A gRPC metadata header name is not a string.');
            }

            $key = strtolower($key);

            if (
                str_starts_with($key, ':')
                || str_starts_with($key, 'grpc-')
                || in_array($key, Metadata::OWNED_KEYS, true)
                || preg_match('/^[0-9a-z_.-]+$/D', $key) !== 1
            ) {
                continue;
            }

            foreach (self::normalizeWireValues($wireValues) as $wireValue) {
                if (str_ends_with($key, '-bin')) {
                    foreach (explode(',', $wireValue) as $encodedValue) {
                        $metadata[$key][] = self::decodeBinaryValue(trim($encodedValue, " \t"));
                    }

                    continue;
                }

                $value = trim($wireValue, ' ');

                if (preg_match('/^[\x20-\x7e]*$/D', $value) === 1) {
                    $metadata[$key][] = $value;
                }
            }
        }

        return Metadata::make($metadata);
    }

    /**
     * Calculate an HTTP/2 header-list size from wire values.
     *
     * @param array<array-key, list<string>|string> $headers
     */
    public static function wireSize(array $headers): int
    {
        $size = 0;

        foreach ($headers as $key => $wireValues) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('A wire header name must be a string.');
            }

            foreach (self::normalizeWireValues($wireValues, protocolException: false) as $wireValue) {
                $size += strlen($key) + strlen($wireValue) + 32;
            }
        }

        return $size;
    }

    /**
     * Normalize one transport field into its visible values.
     *
     * @return list<string>
     */
    private static function normalizeWireValues(
        string|array $wireValues,
        bool $protocolException = true,
    ): array {
        $wireValues = is_array($wireValues) ? $wireValues : [$wireValues];

        if ($wireValues === [] || ! array_is_list($wireValues)) {
            $message = 'A transport header must contain a non-empty list of string values.';

            throw $protocolException
                ? new ProtocolException($message)
                : new InvalidArgumentException($message);
        }

        foreach ($wireValues as $wireValue) {
            if (! is_string($wireValue)) {
                $message = 'A transport header value is not a string.';

                throw $protocolException
                    ? new ProtocolException($message)
                    : new InvalidArgumentException($message);
            }
        }

        return $wireValues;
    }

    /**
     * Decode one padded or unpadded binary metadata value.
     */
    private static function decodeBinaryValue(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder === 1) {
            throw new ProtocolException('A binary gRPC metadata value is not valid base64.');
        }

        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            throw new ProtocolException('A binary gRPC metadata value is not valid base64.');
        }

        return $decoded;
    }
}
