<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema\Types;

use BackedEnum;
use Hypervel\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\Serializer;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

abstract class Type extends JsonSchema
{
    /**
     * Whether the type is required.
     */
    protected ?bool $required = null;

    /**
     * The type's title.
     */
    protected ?string $title = null;

    /**
     * The type's description.
     */
    protected ?string $description = null;

    /**
     * The default value for the type.
     */
    protected mixed $default = null;

    /**
     * Whether a default value was provided.
     */
    protected bool $hasDefault = false;

    /**
     * The set of allowed values for the type.
     *
     * @var null|array<int, mixed>
     */
    protected ?array $enum = null;

    /**
     * Indicates if the type is nullable.
     */
    protected ?bool $nullable = null;

    /**
     * Indicate that the type is required.
     */
    public function required(bool $required = true): static
    {
        $this->required = $required ?: null;

        return $this;
    }

    /**
     * Indicate that the type may be null.
     */
    public function nullable(bool $nullable = true): static
    {
        $this->nullable = $nullable ?: null;

        return $this;
    }

    /**
     * Set the type's title.
     */
    public function title(string $value): static
    {
        $this->title = $value;

        return $this;
    }

    /**
     * Set the type's description.
     */
    public function description(string $value): static
    {
        $this->description = $value;

        return $this;
    }

    /**
     * Set the type's default value.
     */
    protected function setDefault(mixed $value): static
    {
        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }

    /**
     * Restrict the value to one of the provided enumerated values.
     *
     * @param array<int, mixed>|class-string<BackedEnum> $values
     *
     * @throws InvalidArgumentException
     */
    public function enum(array|string $values): static
    {
        if (is_string($values)) {
            if (! is_subclass_of($values, BackedEnum::class)) {
                throw new InvalidArgumentException('The provided class must be a BackedEnum.');
            }

            $values = array_column($values::cases(), 'value');
        }

        // Keep order and allow complex values (arrays / objects) without forcing uniqueness...
        $this->enum = array_values($values);

        return $this;
    }

    /**
     * Convert the type to an array.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function toArray(): array
    {
        return Serializer::serialize($this);
    }

    /**
     * Convert the type to its string representation.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws RuntimeException
     */
    public function toString(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * Convert the type to its string representation.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws RuntimeException
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
