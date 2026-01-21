<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hypervel\Database\Eloquent\ModelNotFoundException;

trait HasUniqueStringIds
{
    /**
     * Generate a new unique key for the model.
     */
    abstract public function newUniqueId(): string;

    /**
     * Determine if given key is valid.
     */
    abstract protected function isValidUniqueId(mixed $value): bool;

    /**
     * Initialize the trait.
     */
    public function initializeHasUniqueStringIds(): void
    {
        $this->usesUniqueIds = true;
    }

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return $this->usesUniqueIds() ? [$this->getKeyName()] : parent::uniqueIds();
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param  \Hypervel\Database\Eloquent\Model|\Hypervel\Database\Eloquent\Relations\Relation<*, *, *>  $query
     * @return \Hypervel\Database\Contracts\Eloquent\Builder
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException
     */
    public function resolveRouteBindingQuery(mixed $query, mixed $value, ?string $field = null)
    {
        if ($field && in_array($field, $this->uniqueIds()) && ! $this->isValidUniqueId($value)) {
            $this->handleInvalidUniqueId($value, $field);
        }

        if (! $field && in_array($this->getRouteKeyName(), $this->uniqueIds()) && ! $this->isValidUniqueId($value)) {
            $this->handleInvalidUniqueId($value, $field);
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    /**
     * Get the auto-incrementing key type.
     */
    public function getKeyType(): string
    {
        if (in_array($this->getKeyName(), $this->uniqueIds())) {
            return 'string';
        }

        return parent::getKeyType();
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     */
    public function getIncrementing(): bool
    {
        if (in_array($this->getKeyName(), $this->uniqueIds())) {
            return false;
        }

        return parent::getIncrementing();
    }

    /**
     * Throw an exception for the given invalid unique ID.
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException
     */
    protected function handleInvalidUniqueId(mixed $value, ?string $field): never
    {
        throw (new ModelNotFoundException)->setModel(get_class($this), $value);
    }
}
