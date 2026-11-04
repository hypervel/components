<?php

declare(strict_types=1);

namespace App\Traits;

use Hypervel\Database\Eloquent\Relations\BelongsTo;

trait HasParent
{
    /**
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class);
    }
}
