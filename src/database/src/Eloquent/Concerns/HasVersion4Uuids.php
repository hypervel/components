<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hyperf\Stringable\Str;

trait HasVersion4Uuids
{
    use HasUuids;

    /**
     * Generate a new UUID (version 4) for the model.
     */
    public function newUniqueId(): string
    {
        return (string) Str::orderedUuid();
    }
}
