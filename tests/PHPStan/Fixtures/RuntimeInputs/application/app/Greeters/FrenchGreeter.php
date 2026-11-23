<?php

declare(strict_types=1);

namespace RuntimeInputs\Greeters;

class FrenchGreeter implements Greeter
{
    /**
     * Get the greeting.
     */
    public function greet(): string
    {
        return $this->bonjour();
    }

    /**
     * Get the French greeting.
     */
    public function bonjour(): string
    {
        return 'Bonjour';
    }
}
