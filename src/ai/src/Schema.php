<?php

declare(strict_types=1);

namespace Hypervel\Ai;

use Hypervel\Ai\Contracts\Schemable;
use Hypervel\JsonSchema\Types\Type;

class Schema implements Schemable
{
    /**
     * Create a new output schema.
     */
    public function __construct(
        public Type $schema,
        public string $name = 'schema_definition',
        public bool $strict = false
    ) {
    }

    /**
     * Get the name of the schema.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Create a new output schema with the given name.
     */
    public function withName(string $name): self
    {
        // Preserve subclass schema rules when changing only the name.
        $schema = clone $this;
        $schema->name = $name;

        return $schema;
    }

    /**
     * Get the array representation of the schema.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toSchema();
    }

    /**
     * Get the array representation of the schema.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return $this->schema->toArray();
    }
}
