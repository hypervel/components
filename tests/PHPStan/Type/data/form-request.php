<?php

declare(strict_types=1);

namespace FormRequest;

use Hypervel\Contracts\Validation\Validator;
use Hypervel\Foundation\Http\FormRequest;

use function PHPStan\Testing\assertType;

function test(FormRequest $request, bool $condition): void
{
    assertType('Hypervel\Support\ValidatedInput', $request->safe());
    // Hypervel: optional keys, because only() omits keys the validated input does not contain.
    assertType('array{key?: mixed}', $request->safe(['key']));
    assertType('array{key?: mixed}|array{other?: mixed, another?: mixed}', $request->safe($condition ? ['key'] : ['other', 'another']));
    // Hypervel: a top-level numeric rule key, such as a list payload's '*.name', gives int keys.
    assertType('array<mixed>', $request->validated());
}

/**
 * Hypervel: keys the extension cannot completely describe keep the declaration.
 *
 * @param list<string> $keys
 */
function undescribedKeys(FormRequest $request, Validator $validator, array $keys, string $key): void
{
    assertType('array{key?: mixed}', $validator->safe(['key']));
    assertType('array<mixed>', $request->safe(['user.name']));
    assertType('array<mixed>', $request->safe(['items*']));
    assertType('array<mixed>', $request->safe([$key]));
    assertType('array<mixed>', $request->safe(...[$keys]));
}
