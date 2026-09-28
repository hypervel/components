<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\Fixtures\Integration;

enum CategoryBackedEnum: string
{
    case People = 'people';
    case Fruits = 'fruits';

    /**
     * Resolve a category from its code.
     */
    public static function fromCode(string $code): ?self
    {
        return match ($code) {
            'c01' => self::People,
            'c02' => self::Fruits,
            default => null,
        };
    }
}
