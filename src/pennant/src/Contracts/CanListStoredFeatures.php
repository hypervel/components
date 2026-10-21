<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Contracts;

interface CanListStoredFeatures
{
    /**
     * Retrieve the names of all stored features.
     *
     * @return array<string>
     */
    public function stored(): array;
}
