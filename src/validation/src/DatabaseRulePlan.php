<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;

final readonly class DatabaseRulePlan
{
    /**
     * Capture an unresolved declaration without retaining its rule builder.
     */
    public function __construct(
        public string $name,
        public array $parameters,
        public DatabaseConditions $conditions,
    ) {
    }

    /**
     * Read the effective declaration once at the start of a validation run.
     */
    public static function fromRule(Exists|Unique $rule): self
    {
        return new self(
            $rule instanceof Exists ? 'Exists' : 'Unique',
            $rule->queryParameters(),
            $rule->queryConditions()->prepare(),
        );
    }

    /**
     * Build a declaration from already-parsed legacy rule parameters.
     *
     * @param 'Exists'|'Unique' $name
     */
    public static function fromParameters(string $name, array $parameters): self
    {
        $count = match ($name) {
            'Exists' => 2,
            'Unique' => 4,
        };

        return new self(
            $name,
            array_slice($parameters, 0, $count),
            DatabaseConditions::fromParameters(array_slice($parameters, $count)),
        );
    }

    /**
     * Get plain cache data only when the complete declaration can be shared.
     */
    public function cacheData(): ?array
    {
        if (! array_all($this->parameters, static fn (mixed $parameter): bool => is_scalar($parameter) || $parameter === null)) {
            return null;
        }

        $conditions = $this->conditions->cacheData();

        return $conditions === null ? null : [$this->name, $this->parameters, $conditions];
    }

    /**
     * Detach header references when the declaration enters an execution plan.
     */
    public function snapshot(): self
    {
        return new self(
            $this->name,
            array_map(static fn (mixed $parameter): mixed => $parameter, $this->parameters),
            $this->conditions,
        );
    }
}
