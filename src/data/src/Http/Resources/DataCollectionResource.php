<?php

declare(strict_types=1);

namespace Hypervel\Data\Http\Resources;

use Hypervel\Data\Contracts\BaseDataCollectable;
use Hypervel\Data\Contracts\ResponsableData;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Support\Transformation\DataTransformer;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\ProvidesResourceWrapper;
use Hypervel\Http\Resources\Json\ResourceCollection;
use Hypervel\Support\Collection;

class DataCollectionResource extends ResourceCollection implements ProvidesResourceWrapper
{
    public static ?string $wrap = null;

    /**
     * Create a data collection resource adapter.
     *
     * @param Collection<array-key, mixed> $originalItems
     * @param array<array-key, mixed> $transformed
     */
    public function __construct(
        protected readonly BaseDataCollectable&ResponsableData $data,
        protected readonly Collection $originalItems,
        protected readonly array $transformed,
        protected readonly ?string $wrapper,
        protected readonly DataTransformer $transformer,
    ) {
        // A paginator holding the transformed rows would derive its cursors from them, so pagination is added by with().
        parent::__construct($transformed);
    }

    /**
     * Resolve the pre-transformed collection payload.
     */
    public function resolve(?Request $request = null): array
    {
        return $this->transformed;
    }

    /**
     * Get the pagination links and metadata of a paginated collection, matching its transformed output.
     */
    public function with(Request $request): array
    {
        $data = $this->data;

        return $data instanceof PaginatedDataCollection || $data instanceof CursorPaginatedDataCollection
            ? $this->transformer->paginationInformation($data->items(), $this->transformed)
            : [];
    }

    /**
     * Get the per-instance response wrapper.
     */
    public function resourceWrapper(): ?string
    {
        return $this->wrapper;
    }

    /**
     * Get the JSON serialization options for the resource response.
     */
    public function jsonOptions(): int
    {
        $dataClass = $this->data->getDataClass();

        return is_a($dataClass, ResponsableData::class, true)
            ? $dataClass::jsonOptions()
            : 0;
    }

    /**
     * Customize the outgoing resource response.
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        // Collections have no item-owned response hook, so retain the original Data objects.
        $response->original = $this->originalItems;

        $this->data->withResponse($request, $response);
    }

    /**
     * Disable per-item JSON resource inference for pre-transformed values.
     */
    protected function collects(): ?string
    {
        return null;
    }
}
