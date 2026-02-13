<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Validation;

interface ValidatesWhenResolved
{
    /**
     * Validate the given class instance.
     */
    public function validateResolved();
}
