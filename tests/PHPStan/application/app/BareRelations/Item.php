<?php

namespace App\BareRelations;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;

/** Relations here are deliberately left without generic annotations. */
class Item extends Model
{
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
