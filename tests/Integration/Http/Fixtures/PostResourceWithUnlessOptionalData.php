<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;

class PostResourceWithUnlessOptionalData extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first' => $this->unless(false, 'value'),
            'second' => $this->unless(true, 'value'),
            'third' => $this->unless(true, function () {
                return 'value';
            }),
            'fourth' => $this->unless(false, 'value', 'default'),
            'fifth' => $this->unless(false, 'value', function () {
                return 'default';
            }),
        ];
    }
}
