<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Fixtures;

class AspectRegistrationTarget
{
    /**
     * Return the value wrapped by the registered aspects.
     */
    public function value(): string
    {
        return 'value';
    }
}
