<?php

namespace ModelDateColumnCasts;

use App\ModelWithDateColumnCasts;

use function PHPStan\Testing\assertType;

function test(ModelWithDateColumnCasts $model): void
{
    // Hypervel: a class caster's get() also runs for null, so its return type decides nullability.
    assertType('array{raw: string|null, formatted: string|null}', $model->created_at);
    assertType('int|null', $model->updated_at);
    assertType('Carbon\CarbonImmutable|null', $model->deleted_at);
}
