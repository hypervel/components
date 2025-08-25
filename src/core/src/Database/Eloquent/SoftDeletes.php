<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hyperf\Database\Model\SoftDeletes as HyperfSoftDeletes;

trait SoftDeletes
{
    use HyperfSoftDeletes;

    /**
     * Force a hard delete on a soft deleted model without raising any events.
     */
    public function forceDeleteQuietly(): bool
    {
        return static::withoutEvents(fn () => $this->forceDelete());
    }

    /**
     * Restore a soft-deleted model instance without raising any events.
     */
    public function restoreQuietly(): bool
    {
        return static::withoutEvents(fn () => $this->restore());
    }
}
