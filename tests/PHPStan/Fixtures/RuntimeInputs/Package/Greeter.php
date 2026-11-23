<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\RuntimeInputs\Package;

interface Greeter
{
    /**
     * Get the greeting.
     */
    public function greet(): string;
}
