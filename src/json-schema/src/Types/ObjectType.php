<?php

declare(strict_types=1);

namespace Hypervel\JsonSchema\Types;

class ObjectType extends Type
{
    /**
     * Whether additional properties are allowed.
     */
    protected ?bool $additionalProperties = null;

    /**
     * Create a new object type instance.
     *
     * @param array<int|string, Type> $properties
     */
    public function __construct(protected array $properties = [])
    {
    }

    /**
     * Get the named property schemas.
     *
     * @return array<int|string, Type>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * Disallow additional properties.
     */
    public function withoutAdditionalProperties(): static
    {
        $this->additionalProperties = false;

        return $this;
    }

    /**
     * Set the type's default value.
     *
     * @param null|array<int|string, mixed> $value
     */
    public function default(?array $value): static
    {
        return $this->setDefault($value);
    }
}
