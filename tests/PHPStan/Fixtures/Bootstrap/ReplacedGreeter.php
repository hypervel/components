<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Bootstrap;

class ReplacedGreeter
{
    /**
     * Return the original greeting.
     */
    public function greet(): string
    {
        return 'original';
    }
}
