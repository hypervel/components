<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\Json;

use ArrayAccess;
use Hypervel\Container\Container;
use Hypervel\Contracts\Router\UrlRoutable;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\ConditionallyLoadsAttributes;
use Hypervel\Http\Resources\DelegatesToResource;
use JsonException;
use JsonSerializable;

class JsonResource implements ArrayAccess, JsonSerializable, Responsable, UrlRoutable
{
    use ConditionallyLoadsAttributes;
    use DelegatesToResource;

    /**
     * The resource instance.
     */
    public mixed $resource;

    /**
     * The additional data that should be added to the top-level resource array.
     */
    public array $with = [];

    /**
     * The additional meta data that should be added to the resource response.
     *
     * Added during response construction by the developer.
     */
    public array $additional = [];

    /**
     * The "data" wrapper that should be applied.
     */
    public static ?string $wrap = 'data';

    /**
     * Whether to force wrapping even if the $wrap key exists in underlying resource data.
     */
    public static bool $forceWrapping = false;

    /**
     * Create a new resource instance.
     */
    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Create a new resource instance.
     */
    public static function make(mixed ...$parameters): static
    {
        return new static(...$parameters);
    }

    /**
     * Create a new anonymous resource collection.
     */
    public static function collection(mixed $resource): AnonymousResourceCollection
    {
        return tap(static::newCollection($resource), function ($collection) {
            if (property_exists(static::class, 'preserveKeys')) {
                /* @phpstan-ignore property.notFound (checked by property_exists above) */
                $collection->preserveKeys = (new static([]))->preserveKeys === true;
            }
        });
    }

    /**
     * Create a new resource collection instance.
     */
    protected static function newCollection(mixed $resource): AnonymousResourceCollection
    {
        return new AnonymousResourceCollection($resource, static::class);
    }

    /**
     * Resolve the resource to an array.
     */
    public function resolve(?Request $request = null): array
    {
        $data = $this->resolveResourceData(
            $request ?: $this->resolveRequestFromContainer()
        );

        if ($data instanceof Arrayable) {
            $data = $data->toArray();
        } elseif ($data instanceof JsonSerializable) {
            $data = $data->jsonSerialize();
        }

        return $this->filter((array) $data);
    }

    /**
     * Transform the resource into an array.
     */
    public function toAttributes(Request $request): array|Arrayable|JsonSerializable
    {
        if (property_exists($this, 'attributes')) {
            return $this->attributes;
        }

        return $this->toArray($request);
    }

    /**
     * Resolve the resource data to an array.
     */
    public function resolveResourceData(Request $request): array|Arrayable|JsonSerializable
    {
        return $this->toAttributes($request);
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array|Arrayable|JsonSerializable
    {
        if (is_null($this->resource)) {
            return [];
        }

        return is_array($this->resource)
            ? $this->resource
            : $this->resource->toArray();
    }

    /**
     * Convert the resource to JSON.
     *
     * @throws JsonEncodingException
     */
    public function toJson(int $options = 0): string
    {
        try {
            $json = json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw JsonEncodingException::forResource($this, $e->getMessage());
        }

        return $json;
    }

    /**
     * Convert the resource to pretty print formatted JSON.
     *
     * @throws JsonEncodingException
     */
    public function toPrettyJson(int $options = 0): string
    {
        return $this->toJson(JSON_PRETTY_PRINT | $options);
    }

    /**
     * Get any additional data that should be returned with the resource array.
     */
    public function with(Request $request): array
    {
        return $this->with;
    }

    /**
     * Add additional meta data to the resource response.
     */
    public function additional(array $data): static
    {
        $this->additional = $data;

        return $this;
    }

    /**
     * Get the JSON serialization options that should be applied to the resource response.
     */
    public function jsonOptions(): int
    {
        return 0;
    }

    /**
     * Customize the response for a request.
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
    }

    /**
     * Resolve the HTTP request instance from container.
     */
    protected function resolveRequestFromContainer(): Request
    {
        return Container::getInstance()->make('request');
    }

    /**
     * Set the string that should wrap the outer-most resource array.
     */
    public static function wrap(?string $value): void
    {
        static::$wrap = $value;
    }

    /**
     * Disable wrapping of the outer-most resource array.
     */
    public static function withoutWrapping(): void
    {
        static::$wrap = null;
    }

    /**
     * Transform the resource into an HTTP response.
     */
    public function response(?Request $request = null): JsonResponse
    {
        return $this->toResponse(
            $request ?: $this->resolveRequestFromContainer()
        );
    }

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): JsonResponse
    {
        return (new ResourceResponse($this))->toResponse($request);
    }

    /**
     * Prepare the resource for JSON serialization.
     */
    public function jsonSerialize(): array
    {
        return $this->resolve($this->resolveRequestFromContainer());
    }

    /**
     * Flush the resource's global state.
     */
    public static function flushState(): void
    {
        static::$wrap = 'data';
        static::$forceWrapping = false;
    }
}
