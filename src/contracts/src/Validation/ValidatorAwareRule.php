<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Validation;

use Hypervel\Validation\Validator;

interface ValidatorAwareRule
{
    /**
     * Set the current validator.
     *
     * @return $this
     */
    public function setValidator(Validator $validator): static;
}
