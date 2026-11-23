<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;

final readonly class DatabaseRulePlan
{
    /**
     * Capture an unresolved declaration without retaining its rule builder.
     *
     * @param null|array $extra The extra parameters as written in a legacy rule, or null to render them from the conditions
     */
    public function __construct(
        public string $name,
        public array $parameters,
        public DatabaseConditions $conditions,
        private ?array $extra = null,
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

        $extra = array_slice($parameters, $count);

        return new self(
            $name,
            array_slice($parameters, 0, $count),
            DatabaseConditions::fromParameters($extra),
            $extra,
        );
    }

    /**
     * Get the complete legacy parameter list reported with a failure.
     *
     * @param array $header The header as validated, after its field references were resolved
     */
    public function failureParameters(array $header): array
    {
        if ($this->extra !== null) {
            return [...$header, ...$this->extra];
        }

        // A fluent unique rule keeps a missing exclusion native; its legacy form spells it NULL.
        if ($this->name === 'Unique') {
            $header[2] ??= 'NULL';
        }

        return [...$header, ...$this->conditions->toParameters(omitOpaqueOperands: true)];
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
            $this->extra,
        );
    }
}
