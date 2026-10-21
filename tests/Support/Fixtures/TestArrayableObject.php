<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Fixtures;

use Hypervel\Contracts\Support\Arrayable;

class TestArrayableObject implements Arrayable
{
    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return ['foo' => 'bar'];
    }
}
