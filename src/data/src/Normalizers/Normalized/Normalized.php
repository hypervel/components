<?php

declare(strict_types=1);

namespace Hypervel\Data\Normalizers\Normalized;

use Hypervel\Data\Support\DataProperty;

interface Normalized
{
    /**
     * Get a property from the normalized source.
     */
    public function getProperty(string $name, DataProperty $dataProperty): mixed;
}
