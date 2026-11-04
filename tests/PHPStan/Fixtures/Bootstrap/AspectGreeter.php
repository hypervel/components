<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Bootstrap;

class AspectGreeter
{
    /**
     * Greet the given name.
     */
    public function greet(string $name): string
    {
        return "Hello {$name}";
    }

    /**
     * Return the greeting count with a deliberately wrong type.
     */
    public function count(): int
    {
        return 'one';
    }
}
