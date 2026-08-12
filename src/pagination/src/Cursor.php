<?php

declare(strict_types=1);

namespace Hypervel\Pagination;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Collection;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/** @implements Arrayable<array-key, mixed> */
class Cursor implements Arrayable
{
    /**
     * Create a new cursor instance.
     *
     * @param array<array-key, mixed> $parameters the parameters associated with the cursor
     * @param bool $pointsToNextItems determine whether the cursor points to the next or previous set of items
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        protected array $parameters,
        protected bool $pointsToNextItems = true,
    ) {
        // Query Builder's flattenValue() silently binds only the first nested scalar from an array.
        foreach ($parameters as $parameterName => $parameter) {
            if (is_array($parameter)) {
                throw new InvalidArgumentException("Cursor parameter [{$parameterName}] must not be an array.");
            }
        }
    }

    /**
     * Get the given parameter from the cursor.
     *
     * @throws UnexpectedValueException
     */
    public function parameter(string $parameterName): mixed
    {
        if (! array_key_exists($parameterName, $this->parameters)) {
            throw new UnexpectedValueException("Unable to find parameter [{$parameterName}] in pagination item.");
        }

        return $this->parameters[$parameterName];
    }

    /**
     * Get the given parameters from the cursor.
     *
     * @param array<int, string> $parameterNames
     * @return array<int, mixed>
     */
    public function parameters(array $parameterNames): array
    {
        return (new Collection($parameterNames))
            ->map(fn ($parameterName) => $this->parameter($parameterName))
            ->toArray();
    }

    /**
     * Determine whether the cursor points to the next set of items.
     */
    public function pointsToNextItems(): bool
    {
        return $this->pointsToNextItems;
    }

    /**
     * Determine whether the cursor points to the previous set of items.
     */
    public function pointsToPreviousItems(): bool
    {
        return ! $this->pointsToNextItems;
    }

    /**
     * Get the array representation of the cursor.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->parameters, [
            '_pointsToNextItems' => $this->pointsToNextItems,
        ]);
    }

    /**
     * Get the encoded string representation of the cursor to construct a URL.
     *
     * @throws JsonException
     */
    public function encode(): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($this->toArray(), JSON_THROW_ON_ERROR)));
    }

    /**
     * Get a cursor instance from the encoded string representation.
     */
    public static function fromEncoded(mixed $encodedString): ?static
    {
        if (! is_string($encodedString)) {
            return null;
        }

        $parameters = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $encodedString)), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // Validate the complete envelope here so strict construction cannot raise a TypeError for its direction.
        if (! is_array($parameters)
            || ! array_key_exists('_pointsToNextItems', $parameters)
            || ! is_bool($parameters['_pointsToNextItems'])) {
            return null;
        }

        $pointsToNextItems = $parameters['_pointsToNextItems'];

        unset($parameters['_pointsToNextItems']);

        try {
            return new static($parameters, $pointsToNextItems);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
