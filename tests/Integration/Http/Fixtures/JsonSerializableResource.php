<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use JsonSerializable;

class JsonSerializableResource implements JsonSerializable
{
    public mixed $resource;

    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->resource->id,
        ];
    }
}
