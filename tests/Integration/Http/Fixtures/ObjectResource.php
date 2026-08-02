<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;

class ObjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->first_name,
            'age' => $this->age,
        ];
    }
}
