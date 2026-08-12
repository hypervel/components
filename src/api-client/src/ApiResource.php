<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

use ArrayAccess;
use Hypervel\ApiClient\Exceptions\InvalidResourceDataException;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Support\Traits\ForwardsCalls;
use JsonException;
use JsonSerializable;
use Stringable;

/**
 * @implements Arrayable<array-key, mixed>
 * @mixin ApiResponse
 */
class ApiResource implements Stringable, ArrayAccess, JsonSerializable, Arrayable, Jsonable
{
    use ForwardsCalls;

    /**
     * Create a new resource instance.
     */
    public function __construct(
        protected ApiResponse $response,
        protected ApiRequest $request
    ) {
    }

    /**
     * Get the resource body as a string.
     */
    public function __toString(): string
    {
        return $this->response->body();
    }

    /**
     * Determine if an attribute exists on the resource.
     *
     * @throws JsonException
     */
    public function __isset(string $key): bool
    {
        $decoded = $this->response->json();

        return is_array($decoded) && isset($decoded[$key]);
    }

    /**
     * Unset an attribute on the resource.
     */
    public function __unset(string $key): void
    {
        $this->response->offsetUnset($key);
    }

    /**
     * Dynamically get properties from the underlying resource.
     *
     * @throws InvalidResourceDataException
     * @throws JsonException
     */
    public function __get(string $key): mixed
    {
        return $this->response->offsetGet($key);
    }

    /**
     * Dynamically pass method calls to the underlying resource.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->response, $method, $parameters);
    }

    /**
     * Get the API response.
     */
    public function getResponse(): ApiResponse
    {
        return $this->response;
    }

    /**
     * Get the API request.
     */
    public function getRequest(): ApiRequest
    {
        return $this->request;
    }

    /**
     * Create a new resource instance.
     */
    public static function make(ApiResponse $response, ApiRequest $request): static
    {
        return new static($response, $request);
    }

    /**
     * Resolve the resource to an array.
     *
     * @return array<array-key, mixed>
     * @throws InvalidResourceDataException
     * @throws JsonException
     */
    public function resolve(): array
    {
        return $this->toArray();
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<array-key, mixed>
     * @throws InvalidResourceDataException
     * @throws JsonException
     */
    public function toArray(): array
    {
        return $this->response->toArray();
    }

    /**
     * Convert the resource to its JSON representation.
     *
     * @throws InvalidResourceDataException
     * @throws JsonException
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Convert the resource to its pretty-printed JSON representation.
     *
     * @throws InvalidResourceDataException
     * @throws JsonException
     */
    public function toPrettyJson(int $options = 0): string
    {
        return $this->toJson(JSON_PRETTY_PRINT | $options);
    }

    /**
     * Prepare the resource for JSON serialization.
     *
     * @return array<array-key, mixed>
     * @throws InvalidResourceDataException
     * @throws JsonException
     */
    public function jsonSerialize(): array
    {
        return $this->resolve();
    }

    /**
     * Determine if the given offset exists.
     *
     * @throws JsonException
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->response->offsetExists($offset);
    }

    /**
     * Get the value for a given offset.
     *
     * @throws InvalidResourceDataException
     * @throws JsonException
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->response->offsetGet($offset);
    }

    /**
     * Set the value at the given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->response->offsetSet($offset, $value);
    }

    /**
     * Unset the value at the given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->response->offsetUnset($offset);
    }
}
