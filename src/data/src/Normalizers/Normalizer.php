<?php

declare(strict_types=1);

namespace Hypervel\Data\Normalizers;

use Hypervel\Data\Normalizers\Normalized\Normalized;

interface Normalizer
{
    /**
     * Normalize a source value.
     */
    public function normalize(mixed $value): array|Normalized|null;
}
