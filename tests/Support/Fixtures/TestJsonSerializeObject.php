<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Fixtures;

use JsonSerializable;

class TestJsonSerializeObject implements JsonSerializable
{
    /**
     * Get the data to serialize as JSON.
     */
    public function jsonSerialize(): array
    {
        return ['foo' => 'bar'];
    }
}
