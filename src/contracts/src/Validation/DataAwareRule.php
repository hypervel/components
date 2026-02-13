<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Validation;

interface DataAwareRule
{
    /**
     * Set the data under validation.
     */
    public function setData(array $data): static;
}
