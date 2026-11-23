<?php

namespace App\BareRelations;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;

/** Relations here are deliberately left without generic annotations. */
class Owner extends Model
{
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
