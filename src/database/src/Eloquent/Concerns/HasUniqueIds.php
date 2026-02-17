<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

trait HasUniqueIds
{
    /**
     * Indicates if the model uses unique ids.
     */
    public bool $usesUniqueIds = false;

    /**
     * Determine if the model uses unique ids.
     */
    public function usesUniqueIds(): bool
    {
        return $this->usesUniqueIds;
    }

    /**
     * Generate unique keys for the model.
     */
    public function setUniqueIds(): void
    {
        foreach ($this->uniqueIds() as $column) {
            if (empty($this->{$column})) {
                $this->{$column} = $this->newUniqueId();
            }
        }
    }

    /**
     * Generate a new key for the model.
     */
    public function newUniqueId(): ?string
    {
        return null;
    }

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return [];
    }
}
