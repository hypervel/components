<?php

declare(strict_types=1);

namespace Hypervel\Permission\Support;

final readonly class PermissionPartition
{
    /**
     * Create a resolved permission partition.
     */
    public function __construct(
        public string $column,
        public int|string $value,
    ) {
    }

    /**
     * Determine whether a value represents this partition.
     */
    public function matches(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && (string) $value === (string) $this->value;
    }

    /**
     * Build the collision-safe cache segment for this partition.
     */
    public function cacheSegment(): string
    {
        return self::encodeCacheSegment($this->column)
            . ':' . self::encodeCacheSegment($this->value);
    }

    /**
     * Encode a nullable scalar as a collision-safe cache segment.
     */
    public static function encodeCacheSegment(int|string|null $value): string
    {
        $value = $value === null ? 'n:' : 'v:' . (string) $value;

        return strlen($value) . ':' . $value;
    }
}
