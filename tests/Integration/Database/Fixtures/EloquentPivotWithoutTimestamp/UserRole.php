<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\EloquentPivotWithoutTimestamp;

use Hypervel\Database\Eloquent\Relations\Pivot;

class UserRole extends Pivot
{
    protected ?string $table = 'role_user';

    /**
     * Get the updated-at column name.
     */
    public function getUpdatedAtColumn(): ?string
    {
        return null;
    }
}
