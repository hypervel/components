<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Ai;

/**
 * @internal
 */
class AiDataSanitizer
{
    /** Maximum total byte size for serialized message data. */
    public const int MAX_MESSAGE_BYTES = 20000;

    /** Maximum byte length for a single message's content string. */
    public const int MAX_SINGLE_MESSAGE_CONTENT_BYTES = 10000;

    /** Preserve readable text while replacing invalid UTF-8 in telemetry. */
    public const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /** Placeholder for binary content that should not be sent to Sentry. */
    public const string BLOB_SUBSTITUTE = '[Blob substitute]';

    /** Regex pattern to detect data URIs (e.g. data:image/png;base64,...). */
    private const string DATA_URI_PATTERN = '/^data:([^;,]+)?(?:;([^,]*))?,/s';

    /** Regex pattern to detect standalone base64-encoded strings (100+ chars). */
    private const string BASE64_PATTERN = '/^[A-Za-z0-9+\/]{100,}={0,2}$/';

    /**
     * Limit a string to the configured byte budget.
     */
    public static function truncateString(?string $value, int $maxBytes = self::MAX_MESSAGE_BYTES): ?string
    {
        if ($value === null) {
            return null;
        }

        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        return mb_strcut($value, 0, $maxBytes, 'UTF-8') . '...(truncated)';
    }

    /**
     * Limit the byte length of one message's content without splitting a UTF-8 character.
     */
    public static function truncateContentString(string $value): string
    {
        if (strlen($value) <= self::MAX_SINGLE_MESSAGE_CONTENT_BYTES) {
            return $value;
        }

        return mb_strcut($value, 0, self::MAX_SINGLE_MESSAGE_CONTENT_BYTES, 'UTF-8') . '...';
    }

    /**
     * Replace encoded binary content with a placeholder.
     */
    public static function redactBinaryInString(string $value): string
    {
        if (self::isBinaryString($value)) {
            return self::BLOB_SUBSTITUTE;
        }

        return $value;
    }

    /**
     * Encode arbitrary values using `json_encode` unless they are strings already, in which
     * case the same string is returned.
     * If encoding fails or exceeds the byte budget, return null. We don't distinguish
     * a lot here between null and false, both mean that we do not want to include them as facts.
     */
    public static function encodeIfNotString(mixed $data = null): ?string
    {
        if ($data === null) {
            return null;
        }
        if (is_string($data)) {
            return $data;
        }
        return self::encode($data);
    }

    /**
     * Encode a structured field within its byte budget, without cutting JSON.
     */
    public static function encode(mixed $data, int $maxBytes = self::MAX_MESSAGE_BYTES): ?string
    {
        $encoded = json_encode($data, self::JSON_FLAGS);

        return $encoded !== false && strlen($encoded) <= $maxBytes ? $encoded : null;
    }

    /**
     * Determine whether the value represents encoded binary content.
     */
    private static function isBinaryString(string $value): bool
    {
        return self::isDataUri($value) || self::isBase64String($value);
    }

    /**
     * Determine whether the value is a data URI.
     */
    private static function isDataUri(string $value): bool
    {
        return (bool) preg_match(self::DATA_URI_PATTERN, $value);
    }

    /**
     * Determine whether the value resembles a base64 payload.
     */
    private static function isBase64String(string $value): bool
    {
        return (bool) preg_match(self::BASE64_PATTERN, $value);
    }
}
