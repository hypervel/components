<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Bootstrap;

class ReplacedGreeter
{
    /**
     * Return the replacement greeting.
     */
    public function greet(): string
    {
        return 'replacement';
    }

    /**
     * Return a value only the replacement provides.
     */
    public function replacementOnly(): int
    {
        return 1;
    }
}
