<?php

declare(strict_types=1);

namespace RuntimeInputs\Greeters;

interface Greeter
{
    /**
     * Get the greeting.
     */
    public function greet(): string;
}
