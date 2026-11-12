<?php

declare(strict_types=1);

namespace Hypervel\Types\Attribute;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Hypervel\Database\Eloquent\Casts\Attribute;

use function PHPStan\Testing\assertType;

/**
 * A declaration may read wider and accept narrower than the callbacks infer.
 *
 * @return Attribute<null|CarbonInterface, int|string>
 */
function widerDeclaration(): Attribute
{
    return Attribute::make(fn () => new CarbonImmutable, fn (string|int|null $value) => $value);
}

assertType('Hypervel\Database\Eloquent\Casts\Attribute<int, string>', Attribute::make(
    function ($value, $attributes) {
        assertType('mixed', $value);
        assertType('array<string, mixed>', $attributes);

        return 5;
    },
    fn (string $value) => strtolower($value),
));
assertType('Hypervel\Database\Eloquent\Casts\Attribute<string, never>', Attribute::make(get: fn (string $value) => ucfirst($value)));
assertType('Hypervel\Database\Eloquent\Casts\Attribute<mixed, never>', Attribute::make(get: fn ($value) => $value));
assertType('Hypervel\Database\Eloquent\Casts\Attribute<never, string>', Attribute::make(set: fn (string $value) => $value));
assertType('Hypervel\Database\Eloquent\Casts\Attribute<string, never>', Attribute::get(fn (string $value) => ucfirst($value)));
assertType('Hypervel\Database\Eloquent\Casts\Attribute<never, string>', Attribute::set(fn (string $value) => ['name' => $value]));
assertType('Hypervel\Database\Eloquent\Casts\Attribute<string, string>', new Attribute(fn (string $value) => ucfirst($value), fn (string $value) => $value));
assertType('Hypervel\Database\Eloquent\Casts\Attribute<5, mixed>', new Attribute(fn () => 5));
assertType('Hypervel\Database\Eloquent\Casts\Attribute<int, never>', Attribute::get(fn () => 5)->shouldCache()->withoutObjectCaching());
