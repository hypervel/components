<?php

declare(strict_types=1);

namespace Hypervel\Support;

use JsonSerializable;

class UndefinedValue implements JsonSerializable
{
    /**
     * Determine if value is equivalent to "undefined" or "null".
     */
    public static function equalsTo(mixed $value): bool
    {
        return $value instanceof UndefinedValue || $value === null;
    }

    /**
     * Get the value for JSON serialization.
     */
    public function jsonSerialize(): null
    {
        return null;
    }
}
