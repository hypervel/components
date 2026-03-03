<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\JsonApi;

use Hypervel\Container\Container;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;
use JsonSerializable;
use Override;

class AnonymousResourceCollection extends \Hypervel\Http\Resources\Json\AnonymousResourceCollection
{
    use Concerns\ResolvesJsonApiRequest;

    /**
     * Get any additional data that should be returned with the resource array.
     */
    #[Override]
    public function with(Request $request): array
    {
        return array_filter([
            'included' => $this->collection
                ->map(fn ($resource) => $resource->resolveIncludedResourceObjects($request))
                ->flatten(depth: 1)
                ->uniqueStrict('_uniqueKey')
                ->map(fn ($included) => Arr::except($included, ['_uniqueKey']))
                ->values()
                ->all(),
            ...($implementation = JsonApiResource::$jsonApiInformation)
                ? ['jsonapi' => $implementation]
                : [],
        ]);
    }

    /**
     * Transform the resource into a JSON array.
     */
    #[Override]
    public function toAttributes(Request $request): array|Arrayable|JsonSerializable
    {
        return $this->collection
            ->map(fn ($resource) => $resource->resolveResourceData($request))
            ->all();
    }

    /**
     * Customize the outgoing response for the resource.
     */
    #[Override]
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->header('Content-Type', 'application/vnd.api+json');
    }

    /**
     * Create an HTTP response that represents the object.
     */
    #[Override]
    public function toResponse(Request $request): JsonResponse
    {
        return parent::toResponse($this->resolveJsonApiRequestFrom($request));
    }

    /**
     * Resolve the HTTP request instance from container.
     */
    #[Override]
    protected function resolveRequestFromContainer(): JsonApiRequest
    {
        return $this->resolveJsonApiRequestFrom(Container::getInstance()->make('request'));
    }
}
