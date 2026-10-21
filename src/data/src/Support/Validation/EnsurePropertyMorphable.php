<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

use Closure;
use Hypervel\Contracts\Validation\ValidationRule;

/**
 * Rejects the discriminator of a property-morphable node whose morph Fill could not resolve.
 *
 * The compiler adds it only to such nodes, reusing Fill's resolution instead of calling morph() again.
 */
class EnsurePropertyMorphable implements ValidationRule
{
    /**
     * Fail the discriminator of an unresolved morph.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $fail('The selected :attribute is invalid for morph.');
    }
}
