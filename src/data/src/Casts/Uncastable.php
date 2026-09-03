<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

enum Uncastable
{
    case Instance;

    /**
     * Get the uncastable sentinel.
     */
    public static function create(): self
    {
        return self::Instance;
    }
}
