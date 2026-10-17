<?php

declare(strict_types=1);

namespace Hypervel\Mcp;

use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Facades\Validator;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\InteractsWithData;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Validation\ValidationException;
use stdClass;

/**
 * @implements Arrayable<array<array-key, mixed>>
 */
class Request implements Arrayable, Transient
{
    use Conditionable;
    use InteractsWithData;
    use Macroable;

    protected ?string $source = null;

    protected ?stdClass $decodedSource = null;

    /**
     * Create a request for an MCP operation.
     *
     * @param array<array-key, mixed> $arguments
     * @param null|array<string, mixed> $meta
     */
    public function __construct(
        protected array $arguments = [],
        protected ?array $meta = null,
        protected ?string $uri = null,
    ) {
    }

    /**
     * Get all or a subset of the arguments.
     *
     * @param null|array-key|array<array-key, string> $keys
     * @return array<array-key, mixed>
     */
    public function all(mixed $keys = null): array
    {
        if (is_null($keys)) {
            return $this->data();
        }

        return array_intersect_key($this->data(), array_flip(is_array($keys) ? $keys : func_get_args()));
    }

    /**
     * Retrieve an argument by its dot path.
     */
    protected function data(mixed $key = null, mixed $default = null): mixed
    {
        return data_get($this->arguments, $key, $default);
    }

    /**
     * Get an argument from the request.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data($key, $default);
    }

    /**
     * Merge arguments into the request.
     *
     * @param array<array-key, mixed> $data
     */
    public function merge(array $data): static
    {
        $this->arguments = array_merge($this->arguments, $data);

        return $this;
    }

    /**
     * Get the request arguments as an array.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->arguments;
    }

    /**
     * Validate the request arguments.
     *
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $messages
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $rules, array $messages = [], array $attributes = []): array
    {
        return Validator::validate($this->all(), $rules, $messages, $attributes);
    }

    /**
     * Get the authenticated user.
     */
    public function user(?string $guard = null): ?Authenticatable
    {
        $auth = Container::getInstance()->make('auth');

        return call_user_func($auth->userResolver(), $guard);
    }

    /**
     * Get the request metadata.
     *
     * @return null|array<string, mixed>
     */
    public function meta(): ?array
    {
        return $this->meta;
    }

    /**
     * Get the resource URI.
     */
    public function uri(): ?string
    {
        return $this->uri;
    }

    /**
     * Get the original JSON-RPC message.
     *
     * @internal
     */
    public function source(): ?string
    {
        return $this->source;
    }

    /**
     * Preserve the wire shapes when forwarding nested tool arguments.
     *
     * @internal
     */
    public function sourceForArguments(string $key): ?string
    {
        if ($this->source === null) {
            return null;
        }

        $this->decodedSource ??= json_decode($this->source, flags: JSON_THROW_ON_ERROR);

        return json_encode(['params' => ['arguments' => data_get($this->decodedSource, "params.arguments.{$key}")]], JSON_THROW_ON_ERROR);
    }

    /**
     * Determine whether the arguments or a nested value represent an object.
     */
    public function isObject(?string $key = null): bool
    {
        $value = $this->data($key);

        if ($value instanceof stdClass) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        if ($value === [] || ! array_is_list($value)) {
            return true;
        }

        if ($this->source === null) {
            return false;
        }

        // Numeric object properties otherwise become indistinguishable from list indexes.
        $this->decodedSource ??= json_decode($this->source, flags: JSON_THROW_ON_ERROR);

        return data_get($this->decodedSource, $key === null ? 'params.arguments' : "params.arguments.{$key}") instanceof stdClass;
    }

    /**
     * Set the request arguments.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function setArguments(array $arguments): void
    {
        $this->arguments = $arguments;
    }

    /**
     * Set the request metadata.
     *
     * @param null|array<string, mixed> $meta
     */
    public function setMeta(?array $meta): void
    {
        $this->meta = $meta;
    }

    /**
     * Set the resource URI.
     */
    public function setUri(?string $uri): void
    {
        $this->uri = $uri;
    }

    /**
     * Set the original JSON-RPC message.
     *
     * @internal
     */
    public function setSource(?string $source): void
    {
        $this->source = $source;
        $this->decodedSource = null;
    }
}
