<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Support;

use Hypervel\Mcp\Transport\HeaderValue;

enum MirroredParameterType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';

    public const int SAFE_INTEGER = 9007199254740991;

    /**
     * Resolve a supported primitive type from its schema.
     */
    public static function fromSchema(mixed $type): ?self
    {
        return is_string($type) ? self::tryFrom($type) : null;
    }

    /**
     * Convert a compatible value to its header representation.
     */
    public function stringify(mixed $value): ?string
    {
        return match ($this) {
            self::String => is_string($value) ? $value : null,
            self::Boolean => is_bool($value) ? ($value ? 'true' : 'false') : null,
            self::Integer => $this->stringifyInteger($value),
        };
    }

    /**
     * Determine whether the header value matches the given body value.
     */
    public function matches(string $header, mixed $value): bool
    {
        $expected = $this->stringify($value);
        $header = HeaderValue::fromHeader($header)->value;

        return match (true) {
            $expected === null => false,
            $this === self::Integer => $this->normalizeInteger($header) === $expected,
            default => $header === $expected,
        };
    }

    /**
     * Normalize a decimal or scientific integer representation without floating-point rounding.
     */
    private function normalizeInteger(string $header): ?string
    {
        if (preg_match('/\A([+-])?(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-])?(\d+))?\z/', $header, $matches) !== 1) {
            return null;
        }

        $sign = $matches[1];
        $integerPart = $matches[2];
        $fractionPart = ($matches[3] ?? '') !== '' ? $matches[3] : ($matches[4] ?? '');
        $exponentSign = $matches[5] ?? '';
        $exponentDigits = $matches[6] ?? '';

        $digits = ltrim($integerPart . $fractionPart, '0');

        if ($digits === '') {
            return '0';
        }

        $significant = rtrim($digits, '0');
        $trailingZeros = strlen($digits) - strlen($significant);

        $exponent = 0;

        if ($exponentDigits !== '') {
            $trimmedExponent = ltrim($exponentDigits, '0');

            if (strlen($trimmedExponent) > 9) {
                return null;
            }

            $exponent = (int) ($exponentSign . ($trimmedExponent === '' ? '0' : $trimmedExponent));
        }

        $powerOfTen = $exponent - strlen($fractionPart) + $trailingZeros;

        if ($powerOfTen < 0 || strlen($significant) + $powerOfTen > 16) {
            return null;
        }

        return ($sign === '-' ? '-' : '') . $significant . str_repeat('0', $powerOfTen);
    }

    /**
     * Convert an interoperable integer value to a string.
     */
    private function stringifyInteger(mixed $value): ?string
    {
        if (is_float($value) && $value === floor($value) && abs($value) <= self::SAFE_INTEGER) {
            $value = (int) $value;
        }

        if (! is_int($value) || abs($value) > self::SAFE_INTEGER) {
            return null;
        }

        return (string) $value;
    }
}
