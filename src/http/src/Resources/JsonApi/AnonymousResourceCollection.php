<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\JsonApi;

use Hypervel\Container\Container;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\Eloquent\Model;
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
        $included = $this->resolveIncludedResources($request);

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
        if ($this->includedResources === null) {
            $this->loadMissingRequestedRelationships($request);
        }

        return $this->collection
            ->map(fn ($resource) => $resource->resolveResourceData($request))
            ->all();
    }

    /**
     * Load the requested relationships for all of the resources at once.
     */
    protected function loadMissingRequestedRelationships(Request $request): void
    {
        $request = $this->resolveJsonApiRequestFrom($request);
        $resource = $this->collection->first();

        // Honor custom loading before the default batch can populate those relationships.
        if ($resource instanceof JsonApiResource && ! $resource->usesDefaultRelationshipLoading()) {
            $resource->loadMissingRequestedRelationshipsOn(
                $request,
                $this->collection->pluck('resource')->whereInstanceOf(Model::class)
            );
        }

        JsonApiResource::prepareResourceRelationships($this->collection, $request);
    }

    /**
     * Load the requested relationships and resolve the included resources.
     */
    protected function resolveIncludedResources(JsonApiRequest $request): array
    {
        if ($this->includedResources === null) {
            $this->loadMissingRequestedRelationships($request);
            $this->includedResources = JsonApiResource::resolveIncludedResources($this->collection, $request);
        }

        return $this->includedResources;
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
        $this->resolveIncludedResources($request);

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
