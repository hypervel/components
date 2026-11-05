<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Facades;

class UndocumentedFacadeRoot
{
    /**
     * Return a greeting.
     */
    public function greet(): string
    {
        return 'Hello';
    }
}
