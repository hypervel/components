<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Log;
use Hypervel\Validation\Validator;

use function PHPStan\Testing\assertType;

/**
 * Check validation callbacks preserve chaining without losing returned values.
 */
function testValidatorCallbacks(Validator $validator, ?int $value): void
{
    $void = function (): void {};
    $log = fn () => Log::warning('Validation failed.');
    $null = fn () => null;

    assertType(Validator::class, $validator->whenPasses($void));
    assertType(Validator::class, $validator->whenPasses($log));
    assertType(Validator::class, $validator->whenPasses($null));
    assertType(Validator::class, $validator->whenFails($void));
    assertType(Validator::class, $validator->whenFails($log));
    assertType(Validator::class, $validator->whenFails($null));

    assertType('Hypervel\Validation\Validator|int', $validator->whenPasses(fn () => $value));
    assertType('Hypervel\Validation\Validator|int', $validator->whenFails(fn () => $value));
    assertType('42|Hypervel\Validation\Validator', $validator->whenPasses($void, fn () => 42));
    assertType('42|Hypervel\Validation\Validator', $validator->whenFails(fn () => 42, $void));
    assertType('Hypervel\Validation\Validator|false', $validator->whenPasses(fn () => false));
    assertType('0|Hypervel\Validation\Validator', $validator->whenFails(fn () => 0));

    $validator->whenPasses($void)->errors();
    $validator->whenFails($log)->errors();
}
