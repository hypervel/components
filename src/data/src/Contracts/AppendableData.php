<?php

declare(strict_types=1);

namespace Hypervel\Data\Contracts;

interface AppendableData
{
    /**
     * Get the additional data defined by the data object.
     */
    public function with(): array;

    /**
     * Add top-level data to the response.
     */
    public function additional(array $additional): object;

    /**
     * Get the additional response data.
     */
    public function getAdditionalData(): array;
}
