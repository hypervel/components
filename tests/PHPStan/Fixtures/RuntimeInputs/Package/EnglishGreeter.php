<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\RuntimeInputs\Package;

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
