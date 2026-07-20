<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Protocol;

use Google\Rpc\Status as RichStatus;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Status;
use Hypervel\Grpc\StatusCode;
use Throwable;

/**
 * @internal
 */
class StatusCodec
{
    /**
     * Encode a gRPC status as final wire fields.
     *
     * @return array<string, string>
     */
    public static function encode(Status $status): array
    {
        $headers = [
            'grpc-status' => (string) $status->code()->value,
            'grpc-message' => self::encodeMessage($status->message()),
        ];
        $details = $status->details();

        if ($details !== null) {
            $headers['grpc-status-details-bin'] = rtrim(
                base64_encode($details->serializeToString()),
                '=',
            );
        }

        return $headers;
    }

    /**
     * Parse a final gRPC status event.
     *
     * @param array<string, list<string>|string> $headers
     */
    public static function parse(array $headers, int $httpStatus, bool $endStream): ?Status
    {
        if (! $endStream) {
            if (array_key_exists('grpc-status', $headers)) {
                throw new ProtocolException(
                    'The peer sent grpc-status before the response stream ended.',
                );
            }

            return null;
        }

        if (! array_key_exists('grpc-status', $headers)) {
            return self::fromHttpStatus($httpStatus);
        }

        $statusValue = $headers['grpc-status'];

        if (! is_string($statusValue) || preg_match('/^[0-9]+$/D', $statusValue) !== 1) {
            return new Status(
                StatusCode::Unknown,
                'The peer returned a malformed or undefined grpc-status value.',
            );
        }

        $code = StatusCode::tryFrom((int) $statusValue);

        if ($code === null) {
            return new Status(
                StatusCode::Unknown,
                'The peer returned a malformed or undefined grpc-status value.',
            );
        }

        $messageValue = $headers['grpc-message'] ?? '';
        $message = is_string($messageValue) ? self::decodeMessage($messageValue) : '';

        if ($code === StatusCode::Ok) {
            return new Status($code, $message);
        }

        $details = self::decodeDetails($headers['grpc-status-details-bin'] ?? null);

        if ($details === null) {
            return new Status($code, $message);
        }

        if ($details->getCode() !== $code->value) {
            return new Status(
                StatusCode::Internal,
                'The peer returned rich status details with a mismatched gRPC status code.',
            );
        }

        return new Status($code, $details->getMessage(), $details);
    }

    /**
     * Map an HTTP response without grpc-status to a gRPC status.
     */
    public static function fromHttpStatus(int $httpStatus): Status
    {
        $code = match ($httpStatus) {
            400 => StatusCode::Internal,
            401 => StatusCode::Unauthenticated,
            403 => StatusCode::PermissionDenied,
            404 => StatusCode::Unimplemented,
            429, 502, 503, 504 => StatusCode::Unavailable,
            default => StatusCode::Unknown,
        };

        return new Status(
            $code,
            "The peer returned HTTP status {$httpStatus} without grpc-status.",
        );
    }

    /**
     * Determine whether an event is a trailers-only response.
     *
     * @param array<string, list<string>|string> $headers
     */
    public static function isTrailersOnly(
        array $headers,
        bool $initialEvent,
        bool $endStream,
    ): bool {
        return $initialEvent && $endStream && array_key_exists('grpc-status', $headers);
    }

    /**
     * Percent-encode a gRPC status message.
     */
    public static function encodeMessage(string $message): string
    {
        $message = self::scrubUtf8($message);
        $encoded = '';

        for ($index = 0, $length = strlen($message); $index < $length; ++$index) {
            $byte = ord($message[$index]);

            $encoded .= $byte >= 0x20 && $byte <= 0x7E && $byte !== 0x25
                ? $message[$index]
                : sprintf('%%%02X', $byte);
        }

        return $encoded;
    }

    /**
     * Tolerantly decode a gRPC status message.
     */
    public static function decodeMessage(string $message): string
    {
        $decoded = '';

        for ($index = 0, $length = strlen($message); $index < $length; ++$index) {
            if (
                $message[$index] === '%'
                && $index + 2 < $length
                && preg_match(
                    '/^[0-9A-Fa-f]{2}$/D',
                    $message[$index + 1] . $message[$index + 2],
                ) === 1
            ) {
                $decoded .= chr(hexdec($message[$index + 1] . $message[$index + 2]));
                $index += 2;

                continue;
            }

            $decoded .= $message[$index];
        }

        return $decoded;
    }

    /**
     * Decode one optional rich status field.
     */
    private static function decodeDetails(string|array|null $value): ?RichStatus
    {
        if (! is_string($value) || str_contains($value, ',')) {
            return null;
        }

        $remainder = strlen($value) % 4;

        if ($remainder === 1) {
            return null;
        }

        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return null;
        }

        try {
            $status = new RichStatus;
            $status->mergeFromString($decoded);
        } catch (Throwable) {
            return null;
        }

        return $status;
    }

    /**
     * Replace each invalid UTF-8 byte with the Unicode replacement character.
     */
    private static function scrubUtf8(string $value): string
    {
        $scrubbed = '';
        $length = strlen($value);

        for ($index = 0; $index < $length;) {
            $first = ord($value[$index]);
            $sequenceLength = match (true) {
                $first <= 0x7F => 1,
                $first >= 0xC2 && $first <= 0xDF => 2,
                $first >= 0xE0 && $first <= 0xEF => 3,
                $first >= 0xF0 && $first <= 0xF4 => 4,
                default => 0,
            };

            if (
                $sequenceLength !== 0
                && self::isValidUtf8Sequence($value, $index, $sequenceLength)
            ) {
                $scrubbed .= substr($value, $index, $sequenceLength);
                $index += $sequenceLength;

                continue;
            }

            $scrubbed .= "\xef\xbf\xbd";
            ++$index;
        }

        return $scrubbed;
    }

    /**
     * Validate one complete UTF-8 sequence.
     */
    private static function isValidUtf8Sequence(
        string $value,
        int $offset,
        int $sequenceLength,
    ): bool {
        if ($offset + $sequenceLength > strlen($value)) {
            return false;
        }

        if ($sequenceLength === 1) {
            return true;
        }

        $first = ord($value[$offset]);
        $second = ord($value[$offset + 1]);

        if (
            ($first === 0xE0 && ($second < 0xA0 || $second > 0xBF))
            || ($first === 0xED && ($second < 0x80 || $second > 0x9F))
            || ($first === 0xF0 && ($second < 0x90 || $second > 0xBF))
            || ($first === 0xF4 && ($second < 0x80 || $second > 0x8F))
            || ($first !== 0xE0 && $first !== 0xED && $first !== 0xF0 && $first !== 0xF4
                && ($second < 0x80 || $second > 0xBF))
        ) {
            return false;
        }

        for ($index = 2; $index < $sequenceLength; ++$index) {
            $continuation = ord($value[$offset + $index]);

            if ($continuation < 0x80 || $continuation > 0xBF) {
                return false;
            }
        }

        return true;
    }
}
