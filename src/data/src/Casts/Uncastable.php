<?php

declare(strict_types=1);

namespace Hypervel\Data\Casts;

enum Uncastable: string
{
    case Instance = 'Instance';

    /**
     * Get the uncastable sentinel.
     */
    public static function create(): self
    {
        return self::Instance;
    }
}
