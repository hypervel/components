<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Closure;
use Hypervel\Data\Attributes\Concerns\AppliesDatabaseConstraints;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Support\Validation\Constraints\DatabaseConstraint;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Validation\Rules\Exists as BaseExists;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Exists extends ObjectValidationAttribute
{
    use AppliesDatabaseConstraints;

    /**
     * Create a new exists validation attribute.
     *
     * @param null|array<int, Closure|DatabaseConstraint>|Closure|DatabaseConstraint $where
     */
    public function __construct(
        protected string|ExternalReference|null $table = null,
        protected string|ExternalReference|null $column = 'NULL',
        protected string|ExternalReference|null $connection = null,
        protected bool|ExternalReference $withoutTrashed = false,
        protected string|ExternalReference $deletedAtColumn = 'deleted_at',
        protected Closure|DatabaseConstraint|array|null $where = null,
        protected ?BaseExists $rule = null,
    ) {
        if ($rule === null && $table === null) {
            throw CannotBuildValidationRule::create('Could not make exists rule since a table or rule is required.');
        }
    }

    /**
     * Get the Validator rule object.
     */
    public function getRule(ValidationPath $path): object|string
    {
        if ($this->rule !== null) {
            return $this->rule;
        }

        $table = $this->normalizePossibleExternalReferenceParameter($this->table);
        $column = $this->normalizePossibleExternalReferenceParameter($this->column);
        $connection = $this->normalizePossibleExternalReferenceParameter($this->connection);
        $withoutTrashed = $this->normalizePossibleExternalReferenceParameter($this->withoutTrashed);
        $deletedAtColumn = $this->normalizePossibleExternalReferenceParameter($this->deletedAtColumn);

        if (! is_string($table)) {
            throw CannotBuildValidationRule::create('Exists table must resolve to a string.');
        }

        if ($column !== null && ! is_string($column)) {
            throw CannotBuildValidationRule::create('Exists column must resolve to a string or null.');
        }

        if ($connection !== null && ! is_string($connection)) {
            throw CannotBuildValidationRule::create('Exists connection must resolve to a string or null.');
        }

        if (! is_bool($withoutTrashed)) {
            throw CannotBuildValidationRule::create('Exists withoutTrashed must resolve to a boolean.');
        }

        if (! is_string($deletedAtColumn)) {
            throw CannotBuildValidationRule::create('Exists deletedAtColumn must resolve to a string.');
        }

        $rule = new BaseExists(
            $connection !== null && $connection !== '' ? "{$connection}.{$table}" : $table,
            $column ?? 'NULL',
        );

        if ($withoutTrashed) {
            $rule->withoutTrashed($deletedAtColumn);
        }

        if ($this->where !== null) {
            $this->applyDatabaseConstraints($rule, $this->where);
        }

        return $rule;
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'exists';
    }

    /**
     * Create the attribute from parsed string parameters.
     */
    public static function create(string ...$parameters): static
    {
        return new static(rule: new BaseExists($parameters[0], $parameters[1] ?? 'NULL'));
    }
}
