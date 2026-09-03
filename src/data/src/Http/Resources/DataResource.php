<?php

declare(strict_types=1);

namespace Hypervel\Data\Http\Resources;

use Hypervel\Data\Contracts\AppendableData;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\ResponsableData;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Http\Resources\Json\ProvidesResourceWrapper;

class DataResource extends JsonResource implements ProvidesResourceWrapper
{
    public static ?string $wrap = null;

    /**
     * Create a data resource adapter.
     *
     * @param array<array-key, mixed> $transformed
     */
    public function __construct(
        protected readonly BaseData&AppendableData&ResponsableData $data,
        protected readonly array $transformed,
        protected readonly ?string $wrapper,
    ) {
        parent::__construct($data);
    }

    /**
     * Resolve the pre-transformed resource payload.
     */
    public function resolve(?Request $request = null): array
    {
        return $this->transformed;
    }

    /**
     * Get the resolved top-level response data.
     */
    public function with(Request $request): array
    {
        return $this->data->getAdditionalData();
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
        return $this->data::jsonOptions();
    }

    /**
     * Customize the outgoing resource response.
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $this->data->withResponse($request, $response);
    }
}
