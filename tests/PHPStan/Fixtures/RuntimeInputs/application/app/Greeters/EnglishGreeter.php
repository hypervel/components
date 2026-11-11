<?php

declare(strict_types=1);

namespace RuntimeInputs\Greeters;

class EnglishGreeter implements Greeter
{
    /**
     * Get the greeting.
     */
    public function greet(): string
    {
        return 'Hello';
    }
}
