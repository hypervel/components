<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hyperf\Database\Model\SoftDeletes as HyperfSoftDeletes;

/**
 * @method static \Hypervel\Database\Eloquent\Builder<static> withTrashed(bool $withTrashed = true)
 * @method static \Hypervel\Database\Eloquent\Builder<static> onlyTrashed()
 * @method static \Hypervel\Database\Eloquent\Builder<static> withoutTrashed()
 * @method static static restoreOrCreate(array $attributes = [], array $values = [])
 */
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
