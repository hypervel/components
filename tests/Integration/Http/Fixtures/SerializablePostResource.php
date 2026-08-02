<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;

class SerializablePostResource extends JsonResource
{
    public function toArray(Request $request): JsonSerializableResource
    {
        return new JsonSerializableResource($this);
    }
}
