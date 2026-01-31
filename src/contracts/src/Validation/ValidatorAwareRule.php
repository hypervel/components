<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Validation;

interface ValidatorAwareRule
{
    /**
     * Set the current validator.
     */
    public function setValidator(Validator $validator): static;
}
