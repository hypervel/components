<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;

class ResourceWithPreservedKeys extends PostResource
{
    protected bool $preserveKeys = true;

    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
