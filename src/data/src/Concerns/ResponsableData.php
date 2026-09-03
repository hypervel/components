<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Hypervel\Container\Container;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\BaseDataCollectable;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Http\RequestQueryStringPartialsResolver;
use Hypervel\Data\Http\Resources\DataCollectionResource;
use Hypervel\Data\Http\Resources\DataResource;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\Transformation\DataTransformer;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;

trait ResponsableData
{
    /**
     * Create an HTTP response that represents the data object.
     */
    public function toResponse(Request $request): JsonResponse
    {
        $data = $this;
        $container = Container::getInstance();
        $contextFactory = $container
            ->make(RequestQueryStringPartialsResolver::class)
            ->resolve($data, $request, TransformationContextFactory::create());
        $context = $contextFactory->get($data);
        $transformer = $container->make(DataTransformer::class);
        $wrapper = $data->getWrap()->getKey(
            $container->make(DataConfig::class)->wrap,
        );

        if ($data instanceof BaseDataCollectable) {
            $originalItems = $this->responseItems($data);
            $transformed = $transformer->transformForResourceResponse(
                $data,
                $context,
                $originalItems,
            );

            return (new DataCollectionResource(
                $data,
                $originalItems,
                $transformed,
                $wrapper,
            ))->toResponse($request);
        }

        return (new DataResource(
            $data,
            $transformer->transformForResourceResponse($data, $context),
            $wrapper,
        ))->toResponse($request);
    }

    /**
     * Get the JSON serialization options for the resource response.
     */
    public static function jsonOptions(): int
    {
        return 0;
    }

    /**
     * Customize the outgoing resource response.
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
    }

    /**
     * Get the request properties that may be included.
     */
    public static function allowedRequestIncludes(): ?array
    {
        return [];
    }

    /**
     * Get the request properties that may be excluded.
     */
    public static function allowedRequestExcludes(): ?array
    {
        return [];
    }

    /**
     * Get the request properties allowed by an only selection.
     */
    public static function allowedRequestOnly(): ?array
    {
        return [];
    }

    /**
     * Get the request properties allowed by an except selection.
     */
    public static function allowedRequestExcept(): ?array
    {
        return [];
    }

    /**
     * Get original collection items without transforming or enumerating them twice.
     *
     * @return Collection<array-key, BaseData>
     */
    protected function responseItems(BaseDataCollectable $data): Collection
    {
        if ($data instanceof DataCollection) {
            $items = $data->toCollection();

            return $items instanceof Collection ? $items : $items->collect();
        }

        if ($data instanceof PaginatedDataCollection || $data instanceof CursorPaginatedDataCollection) {
            return $data->items()->getCollection();
        }

        return new Collection(iterator_to_array($data));
    }
}
