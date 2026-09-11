<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models\LoadAggregate;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;

class Related1 extends Model
{
    public bool $timestamps = false;

    protected array $fillable = ['base_model_id', 'number'];

    /**
     * Get the parent model.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(BaseModel::class);
    }
}
