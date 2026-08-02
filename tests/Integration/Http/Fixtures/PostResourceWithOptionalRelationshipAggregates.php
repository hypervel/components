<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;

class PostResourceWithOptionalRelationshipAggregates extends PostResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'average_rating' => $this->whenAggregated('comments', 'rating', 'avg'),
            'minimum_rating' => $this->whenAggregated('comments', 'rating', 'min'),
            'maximum_rating' => $this->whenAggregated('comments', 'rating', 'max', fn (mixed $average) => "{$average} ratings", 'Default Value'),
        ];
    }
}
