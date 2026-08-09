<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder\Fixtures\Controllers;

class OptionalController
{
    public function optional(): void
    {
    }

    public function manyOptional(): void
    {
    }

    public function requiredWithOptional(string $required, ?string $one = null, ?string $two = null): void
    {
    }
}
