<?php

declare(strict_types=1);

namespace Hypervel\Data\Normalizers;

use Hypervel\Foundation\Http\FormRequest;

class FormRequestNormalizer implements Normalizer
{
    /**
     * Normalize a form request to its validated input.
     */
    public function normalize(mixed $value): ?array
    {
        if (! $value instanceof FormRequest) {
            return null;
        }

        return $value->validated();
    }
}
