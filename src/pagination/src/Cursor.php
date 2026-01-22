<?php

declare(strict_types=1);

namespace Hypervel\Pagination;

use Hypervel\Collection\Collection;
use Hypervel\Support\Contracts\Arrayable;
use UnexpectedValueException;

/** @implements Arrayable<array-key, mixed> */
class Cursor implements Arrayable
{
    /**
     * Create a new cursor instance.
     *
     * @param  array<string, mixed>  $parameters  The parameters associated with the cursor.
     * @param  bool  $pointsToNextItems  Determine whether the cursor points to the next or previous set of items.
     */
    public function __construct(
        protected array $parameters,
        protected bool $pointsToNextItems = true,
    ) {
    }

    /**
     * Get the given parameter from the cursor.
     *
     * @throws UnexpectedValueException
     */
    public function parameter(string $parameterName): ?string
    {
        if (! array_key_exists($parameterName, $this->parameters)) {
            throw new UnexpectedValueException("Unable to find parameter [{$parameterName}] in pagination item.");
        }

        return $this->parameters[$parameterName];
    }

    /**
     * Get the given parameters from the cursor.
     *
     * @param  array<int, string>  $parameterNames
     * @return array<int, string|null>
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
     */
    public function encode(): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($this->toArray())));
    }

    /**
     * Get a cursor instance from the encoded string representation.
     */
    public static function fromEncoded(?string $encodedString): ?static
    {
        if ($encodedString === null) {
            return null;
        }

        $parameters = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $encodedString)), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $pointsToNextItems = $parameters['_pointsToNextItems'];

        unset($parameters['_pointsToNextItems']);

        return new static($parameters, $pointsToNextItems);
    }
}
