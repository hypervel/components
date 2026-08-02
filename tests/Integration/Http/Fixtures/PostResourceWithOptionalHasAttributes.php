<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;

class PostResourceWithOptionalHasAttributes extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first' => $this->whenHas('is_published'),
            'second' => $this->whenHas('is_published', 'override value'),
            'third' => $this->whenHas('is_published', function () {
                return 'override value';
            }),
            'fourth' => $this->whenHas('is_published', $this->is_published, 'default'),
            'fifth' => $this->whenHas('is_published', $this->is_published, function () {
                return 'default';
            }),
        ];
    }
}
