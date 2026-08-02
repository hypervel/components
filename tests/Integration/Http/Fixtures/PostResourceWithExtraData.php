<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;

class PostResourceWithExtraData extends PostResource
{
    public function with(Request $request): array
    {
        return ['foo' => 'bar'];
    }
}
