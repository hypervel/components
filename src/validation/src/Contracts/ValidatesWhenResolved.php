<?php

declare(strict_types=1);

namespace Illuminate\Contracts\Validation;

interface ValidatesWhenResolved
{
    /**
     * Validate the given class instance.
     */
    public function validateResolved();
}
