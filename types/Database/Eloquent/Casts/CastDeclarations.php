<?php

declare(strict_types=1);

namespace Hypervel\Types\CastDeclarations;

use Hypervel\Database\Eloquent\Casts\AsArrayObject;
use Hypervel\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

/**
 * Casts may be declared as [$cast, ...$arguments], which the model normalizes to strings when it is constructed.
 */
class Product extends Model
{
    protected array $casts = [
        'options' => [AsArrayObject::class],
        'price' => ['decimal', 2],
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'weight' => ['decimal', 3],
        ];
    }
}

assertType('array<string, string>', (new Product)->getCasts());
