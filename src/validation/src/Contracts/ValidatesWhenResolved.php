<?php

declare(strict_types=1);

namespace Hypervel\Validation\Contracts;

interface ValidatesWhenResolved
{
    /**
     * Validate the given class instance.
     */
    public function validateResolved();
}
