<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Support;

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
