<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools;

use ArrayAccess;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Facades\Validator;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\InteractsWithData;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Validation\ValidationException;

class Request implements Arrayable, ArrayAccess
{
    use Conditionable;
    use InteractsWithData;
    use Macroable;

    /**
     * Create a tool request with its arguments and execution identifiers.
     */
    public function __construct(
        protected array $arguments = [],
        protected ?string $toolCallId = null,
        protected ?string $toolInvocationId = null,
    ) {
    }

    /**
     * Get the stable provider tool-call ID, usable as an external idempotency key.
     */
    public function toolCallId(): ?string
    {
        return $this->toolCallId;
    }

    /**
     * Get the ID correlating this execution with the tool invocation events dispatched around it.
     */
    public function toolInvocationId(): ?string
    {
        return $this->toolInvocationId;
    }

    /**
     * Validate the tool arguments against the given rules.
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
     * Get all or a subset of the tool arguments.
     *
     * @param null|array-key|array<array-key, string> $keys
     * @return array<string, mixed>
     */
    public function all(mixed $keys = null): array
    {
        if ($keys === null) {
            return $this->data();
        }

        return array_intersect_key(
            $this->data(),
            array_flip(is_array($keys) ? $keys : func_get_args())
        );
    }

    /**
     * Get a tool argument or all arguments.
     */
    protected function data(mixed $key = null, mixed $default = null): mixed
    {
        return $key === null
            ? $this->arguments
            : ($this->arguments[$key] ?? $default);
    }

    /**
     * Get the tool arguments as an array.
     */
    public function toArray(): array
    {
        return $this->arguments;
    }

    /**
     * Determine if an item exists at an offset.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->arguments[$offset]);
    }

    /**
     * Get an item at a given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->arguments[$offset];
    }

    /**
     * Set the item at a given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->arguments[] = $value;
        } else {
            $this->arguments[$offset] = $value;
        }
    }

    /**
     * Unset the item at a given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->arguments[$offset]);
    }
}
