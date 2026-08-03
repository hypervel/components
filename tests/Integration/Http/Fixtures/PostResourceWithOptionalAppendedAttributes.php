<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;

class PostResourceWithOptionalAppendedAttributes extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first' => $this->whenAppended('is_published'),
            'second' => $this->whenAppended('is_published', 'override value'),
            'third' => $this->whenAppended('is_published', function () {
                return 'override value';
            }),
            'fourth' => $this->whenAppended('is_published', $this->is_published, 'default'),
            'fifth' => $this->whenAppended('is_published', $this->is_published, function () {
                return 'default';
            }),
        ];
    }
}
