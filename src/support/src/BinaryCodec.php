<?php

declare(strict_types=1);

namespace Hypervel\Support;

use InvalidArgumentException;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

class BinaryCodec
{
    private const array BUILT_IN_FORMATS = ['uuid', 'ulid'];

    private const int BINARY_LENGTH = 16;

    /** @var array<string, array{encode: callable(null|string|Ulid|Uuid): ?string, decode: callable(?string): ?string}> */
    protected static array $customCodecs = [];

    /**
     * Register a custom codec.
     *
     * Boot-only. Codecs persist in a static property for the worker lifetime
     * and apply to every subsequent encode/decode call.
     */
    public static function register(string $name, callable $encode, callable $decode): void
    {
        self::$customCodecs[$name] = [
            'encode' => $encode,
            'decode' => $decode,
        ];
    }

    /**
     * Encode a value to binary.
     */
    public static function encode(Uuid|Ulid|string|null $value, string $format): ?string
    {
        $isBuiltInBinary = self::isBuiltInBinary($value, $format);

        if (! $isBuiltInBinary && blank($value)) {
            return null;
        }

        if (isset(self::$customCodecs[$format])) {
            return (self::$customCodecs[$format]['encode'])($value);
        }

        return match ($format) {
            'uuid' => match (true) {
                $value instanceof Uuid => $value->toBinary(),
                $isBuiltInBinary => $value,
                default => Uuid::fromString($value)->toBinary(),
            },
            'ulid' => match (true) {
                $value instanceof Ulid => $value->toBinary(),
                $isBuiltInBinary => $value,
                default => Ulid::fromString($value)->toBinary(),
            },
            default => throw new InvalidArgumentException("Format [{$format}] is invalid."),
        };
    }

    /**
     * Decode a binary value to string.
     */
    public static function decode(?string $value, string $format): ?string
    {
        $isBuiltInBinary = self::isBuiltInBinary($value, $format);

        if (! $isBuiltInBinary && blank($value)) {
            return null;
        }

        if (isset(self::$customCodecs[$format])) {
            return (self::$customCodecs[$format]['decode'])($value);
        }

        return match ($format) {
            'uuid' => ($isBuiltInBinary ? Uuid::fromBinary($value) : Uuid::fromString($value))->toString(),
            'ulid' => ($isBuiltInBinary ? Ulid::fromBinary($value) : Ulid::fromString($value))->toString(),
            default => throw new InvalidArgumentException("Format [{$format}] is invalid."),
        };
    }

    /**
     * Get all available format names.
     *
     * @return list<string>
     */
    public static function formats(): array
    {
        return array_values(array_unique([...self::BUILT_IN_FORMATS, ...array_keys(self::$customCodecs)]));
    }

    /**
     * Determine if the value is an unoverridden built-in binary identifier.
     */
    private static function isBuiltInBinary(mixed $value, string $format): bool
    {
        return is_string($value)
            && strlen($value) === self::BINARY_LENGTH
            && in_array($format, self::BUILT_IN_FORMATS, true)
            && ! isset(self::$customCodecs[$format]);
    }

    /**
     * Determine if the given value is binary data.
     *
     * This is a content heuristic, not an identifier-format check. Pass UUID
     * and ULID values through encode() or decode() instead of choosing a parser.
     */
    public static function isBinary(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        if (str_contains($value, "\0")) {
            return true;
        }

        return ! mb_check_encoding($value, 'UTF-8');
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$customCodecs = [];
    }
}
