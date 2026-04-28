<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hypervel\Support\Str;

trait HasUlids
{
    use HasUniqueStringIds;

    /**
     * Generate a new unique key for the model.
     */
    public function newUniqueId(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * Determine if given key is valid.
     */
    protected function isValidUniqueId(mixed $value): bool
    {
        return Str::isUlid($value);
    }
}
