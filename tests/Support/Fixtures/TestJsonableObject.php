<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Fixtures;

use Hypervel\Contracts\Support\Jsonable;

class TestJsonableObject implements Jsonable
{
    /**
     * Convert the object to its JSON representation.
     */
    public function toJson(int $options = 0): string
    {
        return '{"foo":"bar"}';
    }
}
