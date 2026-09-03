<?php

declare(strict_types=1);

namespace Hypervel\Data\Normalizers\Normalized;

enum UnknownProperty
{
    case Instance;

    /**
     * Get the missing-property sentinel.
     */
    public static function create(): self
    {
        return self::Instance;
    }
}
