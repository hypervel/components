<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data;

use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    /** @var array<int, string> */
    protected array $appends = [
        'computed_property',
        'non_existent',
        'email', // This is a database column, should not be appended
        'name', // This is a column with an accessor, which serialization reads through it
    ];

    protected function computedProperty(): Attribute
    {
        return Attribute::get(fn () => 'foo');
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn ($value) => ucwords($value));
    }
}
