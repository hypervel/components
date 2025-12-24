<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

use ArrayAccess;
use BadMethodCallException;
use Hyperf\Contract\Arrayable;
use Hyperf\Contract\Jsonable;
use Hyperf\Support\Traits\ForwardsCalls;
use JsonSerializable;
use Stringable;

/**
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

    public function __toString(): string
    {
        return $this->response->body();
    }

    /**
     * Determine if an attribute exists on the resource.
     */
    public function __isset(string $key): bool
    {
        return isset($this->response->json()[$key]);
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
        if (! method_exists($this->response, $method)) {
            throw new BadMethodCallException(
                sprintf('Method %s does not exist on %s', $method, get_class($this->response))
            );
        }

        return $this->forwardCallTo($this->response, $method, $parameters);
    }

    public function getResponse(): ApiResponse
    {
        return $this->response;
    }

    public function getRequest(): ApiRequest
    {
        return $this->request;
    }

    /**
     * Create a new resource instance.
     */
    public static function make(mixed ...$parameters): static
    {
        return new static(...$parameters);
    }

    /**
     * Resolve the resource to an array.
     */
    public function resolve(): array
    {
        return $this->toArray();
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray(): array
    {
        return $this->response->json();
    }

    /**
     * Prepare the resource for JSON serialization.
     */
    public function jsonSerialize(): mixed
    {
        return $this->resolve();
    }

    /**
     * Implementation of ArrayAccess::offsetExists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->response->offsetExists($offset);
    }

    /**
     * Implementation of ArrayAccess::offsetGet.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->response->offsetGet($offset);
    }

    /**
     * Implementation of ArrayAccess::offsetSet.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->response->offsetSet($offset, $value);
    }

    /**
     * Implementation of ArrayAccess::offsetUnset.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->response->offsetUnset($offset);
    }
}
