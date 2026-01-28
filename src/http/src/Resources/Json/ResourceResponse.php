<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\Json;

use Hypervel\Contracts\Support\Responsable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;

class ResourceResponse implements Responsable
{
    /**
     * The underlying resource.
     */
    public mixed $resource;

    /**
     * Create a new resource response.
     */
    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): JsonResponse
    {
        return tap(response()->json(
            $this->wrap(
                $this->resource->resolve($request),
                $this->resource->with($request),
                $this->resource->additional
            ),
            $this->calculateStatus(),
            [],
            $this->resource->jsonOptions()
        ), function ($response) use ($request) {
            $response->original = $this->resource->resource;

            $this->resource->withResponse($request, $response);
        });
    }

    /**
     * Wrap the given data if necessary.
     */
    protected function wrap(Collection|array $data, array $with = [], array $additional = []): array
    {
        if ($data instanceof Collection) {
            $data = $data->all();
        }

        if ($this->haveDefaultWrapperAndDataIsUnwrapped($data)) {
            $data = [$this->wrapper() => $data];
        } elseif ($this->haveAdditionalInformationAndDataIsUnwrapped($data, $with, $additional)) {
            $data = [($this->wrapper() ?? 'data') => $data];
        }

        return array_merge_recursive($data, $with, $additional);
    }

    /**
     * Determine if we have a default wrapper and the given data is unwrapped.
     */
    protected function haveDefaultWrapperAndDataIsUnwrapped(array $data): bool
    {
        if ($this->resource instanceof JsonResource && $this->resource::$forceWrapping) {
            return $this->wrapper() !== null;
        }

        return $this->wrapper() && ! array_key_exists($this->wrapper(), $data);
    }

    /**
     * Determine if "with" data has been added and our data is unwrapped.
     */
    protected function haveAdditionalInformationAndDataIsUnwrapped(array $data, array $with, array $additional): bool
    {
        return (! empty($with) || ! empty($additional))
               && (! $this->wrapper()
                || ! array_key_exists($this->wrapper(), $data));
    }

    /**
     * Get the default data wrapper for the resource.
     */
    protected function wrapper(): ?string
    {
        return $this->resource::$wrap;
    }

    /**
     * Calculate the appropriate status code for the response.
     */
    protected function calculateStatus(): int
    {
        return $this->resource->resource instanceof Model
               && $this->resource->resource->wasRecentlyCreated ? 201 : 200;
    }
}
