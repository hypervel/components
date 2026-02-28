<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hypervel\Support\Str;

trait HasUuids
{
    use HasUniqueStringIds;

    /**
     * Generate a new unique key for the model.
     */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * Determine if given key is valid.
     */
    protected function isValidUniqueId(mixed $value): bool
    {
        return Str::isUuid($value);
    }
}
