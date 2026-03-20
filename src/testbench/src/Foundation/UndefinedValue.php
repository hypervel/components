<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation;

use JsonSerializable;

/**
 * @api
 */
final class UndefinedValue implements JsonSerializable
{
    /**
     * Determine if value is equivalent to "undefined" or "null".
     */
    public static function equalsTo(mixed $value): bool
    {
        return $value instanceof self || $value === null;
    }

    /**
     * Get the value for JSON serialization.
     */
    public function jsonSerialize(): mixed
    {
        return null;
    }
}
