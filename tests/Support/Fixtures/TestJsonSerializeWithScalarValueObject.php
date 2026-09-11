<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Fixtures;

use JsonSerializable;

class TestJsonSerializeWithScalarValueObject implements JsonSerializable
{
    /**
     * Get the data to serialize as JSON.
     */
    public function jsonSerialize(): string
    {
        return 'foo';
    }
}
