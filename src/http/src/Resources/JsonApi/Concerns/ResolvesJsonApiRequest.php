<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\JsonApi\Concerns;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\JsonApi\JsonApiRequest;

trait ResolvesJsonApiRequest
{
    /**
     * Resolve a JSON API request instance from the given HTTP request.
     */
    protected function resolveJsonApiRequestFrom(Request $request): JsonApiRequest
    {
        return $request instanceof JsonApiRequest
            ? $request
            : JsonApiRequest::createFrom($request);
    }
}
