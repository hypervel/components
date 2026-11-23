<?php

declare(strict_types=1);

namespace ValidatorSafe;

use Hypervel\Validation\Validator;

use function PHPStan\Testing\assertType;

function test(Validator $validator): void
{
    assertType('Hypervel\Support\ValidatedInput', $validator->safe());
    // Hypervel: optional keys, because only() omits keys the validated input does not contain.
    assertType('array{key?: mixed}', $validator->safe(['key']));
}
