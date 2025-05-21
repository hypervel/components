<?php

declare(strict_types=1);

namespace Hypervel\Validation\Contracts;

interface ValidatorAwareRule
{
    /**
     * Set the current validator.
     */
    public function setValidator(Validator $validator): static;
}
