<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\Json;

use Countable;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\CollectsResources;
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;
use IteratorAggregate;
use Override;

class ResourceCollection extends JsonResource implements Countable, IteratorAggregate
{
    use CollectsResources;

    /**
     * The resource that this resource collects.
     */
    public ?string $collects = null;

    /**
     * The mapped collection instance.
     */
    public mixed $collection = null;

    /**
     * Indicates if all existing request query parameters should be added to pagination links.
     */
    protected bool $preserveAllQueryParameters = false;

    /**
     * The query parameters that should be added to the pagination links.
     */
    protected ?array $queryParameters = null;

    /**
     * Create a new resource instance.
     */
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);

        $this->resource = $this->collectResource($resource);
    }

    /**
     * Indicate that all current query parameters should be appended to pagination links.
     */
    public function preserveQuery(): static
    {
        $this->preserveAllQueryParameters = true;

        return $this;
    }

    /**
     * Specify the query string parameters that should be present on pagination links.
     */
    public function withQuery(array $query): static
    {
        $this->preserveAllQueryParameters = false;

        $this->queryParameters = $query;

        return $this;
    }

    /**
     * Return the count of items in the resource collection.
     */
    public function count(): int
    {
        return $this->collection->count();
    }

    /**
     * Transform the resource into a JSON array.
     */
    #[Override]
    public function toArray(Request $request): array
    {
        if ($this->collection->first() instanceof JsonResource) {
            return $this->collection->map->resolve($request)->all();
        }

        return $this->collection->map->toArray($request)->all();
    }

    /**
     * Create an HTTP response that represents the object.
     */
    #[Override]
    public function toResponse(Request $request): JsonResponse
    {
        if ($this->resource instanceof AbstractPaginator || $this->resource instanceof AbstractCursorPaginator) {
            return $this->preparePaginatedResponse($request);
        }

        return parent::toResponse($request);
    }

    /**
     * Create a paginate-aware HTTP response.
     */
    protected function preparePaginatedResponse(Request $request): JsonResponse
    {
        if ($this->preserveAllQueryParameters) {
            $this->resource->appends($request->query());
        } elseif (! is_null($this->queryParameters)) {
            $this->resource->appends($this->queryParameters);
        }

        return (new PaginatedResourceResponse($this))->toResponse($request);
    }
}
