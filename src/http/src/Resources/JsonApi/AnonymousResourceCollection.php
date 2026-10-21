<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\JsonApi;

use Hypervel\Container\Container;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection as BaseAnonymousResourceCollection;
use JsonSerializable;
use Override;

class AnonymousResourceCollection extends BaseAnonymousResourceCollection
{
    use Concerns\ResolvesJsonApiRequest;

    /**
     * The included resources resolved for this response.
     */
    protected ?array $includedResources = null;

    /**
     * Get any additional data that should be returned with the resource array.
     */
    #[Override]
    public function with(Request $request): array
    {
        $request = $this->resolveJsonApiRequestFrom($request);
        $included = $this->includedResources ??= JsonApiResource::resolveIncludedResources($this->collection, $request);

        return [
            ...($included !== [] || $request->has('include')) ? ['included' => $included] : [],
            ...($implementation = JsonApiResource::$jsonApiInformation)
                ? ['jsonapi' => $implementation]
                : [],
        ];
    }

    /**
     * Transform the resource into a JSON array.
     */
    #[Override]
    public function toAttributes(Request $request): array|Arrayable|JsonSerializable
    {
        JsonApiResource::prepareResourceRelationships($this->collection, $this->resolveJsonApiRequestFrom($request));

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
        $request = $this->resolveJsonApiRequestFrom($request);

        // Merge duplicate linkage before the primary collection is serialized.
        $this->includedResources ??= JsonApiResource::resolveIncludedResources($this->collection, $request);

        return parent::toResponse($request);
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
