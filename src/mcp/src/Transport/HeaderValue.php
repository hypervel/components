<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Transport;

use Stringable;

class HeaderValue implements Stringable
{
    protected const string BASE64_PREFIX = '=?base64?';

    protected const string BASE64_SUFFIX = '?=';

    protected const string BASE64_PATTERN = '/\A(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?\z/';

    /**
     * Create a header value.
     */
    public function __construct(public readonly string $value)
    {
    }

    /**
     * Decode a plain or base64-encoded header.
     */
    public static function fromHeader(string $header): static
    {
        if (! self::isBase64($header)) {
            return new static($header);
        }

        $encoded = substr($header, strlen(self::BASE64_PREFIX), -strlen(self::BASE64_SUFFIX));

        $decoded = preg_match(self::BASE64_PATTERN, $encoded) === 1 ? base64_decode($encoded, true) : false;

        return new static($decoded === false ? $header : $decoded);
    }

    /**
     * Determine whether the value contains only safe HTTP header characters.
     */
    public static function isSafe(string $value): bool
    {
        return preg_match('/\A[\x20-\x7E\x09]*\z/', $value) === 1;
    }

    /**
     * Determine whether the header represents the given value.
     */
    public function matches(string $value): bool
    {
        return $this->value === $value;
    }

    /**
     * Encode the value for use in an HTTP header.
     */
    public function __toString(): string
    {
        if (! self::isBase64($this->value) && preg_match('/^[\x21-\x7E]([\x20-\x7E\x09]*[\x21-\x7E])?\z/', $this->value) === 1) {
            return $this->value;
        }

        return self::BASE64_PREFIX . base64_encode($this->value) . self::BASE64_SUFFIX;
    }

    /**
     * Determine whether the value uses the base64 header envelope.
     */
    protected static function isBase64(string $value): bool
    {
        return str_starts_with($value, self::BASE64_PREFIX) && str_ends_with($value, self::BASE64_SUFFIX);
    }
}
