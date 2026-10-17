<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Transport;

use Stringable;

class HeaderValue implements Stringable
{
    protected const string BASE64_PREFIX = '=?base64?';

    protected const string BASE64_SUFFIX = '?=';

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

        $decoded = base64_decode(substr(
            $header,
            strlen(self::BASE64_PREFIX),
            -strlen(self::BASE64_SUFFIX),
        ), true);

        return new static($decoded === false ? $header : $decoded);
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
