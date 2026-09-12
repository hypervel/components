<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models\LoadAggregate;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;

class BaseModel extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    /**
     * Get the first related models.
     */
    public function related1(): HasMany
    {
        return $this->hasMany(Related1::class);
    }

    /**
     * Get the second related models.
     */
    public function related2(): HasMany
    {
        return $this->hasMany(Related2::class);
    }
}
