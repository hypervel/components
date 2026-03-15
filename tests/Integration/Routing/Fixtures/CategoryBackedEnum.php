<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing\Fixtures;

enum CategoryBackedEnum: string
{
    case People = 'people';
    case Fruits = 'fruits';

    public static function fromCode(string $code): ?self
    {
        return match ($code) {
            'c01' => self::People,
            'c02' => self::Fruits,
            default => null,
        };
    }
}
