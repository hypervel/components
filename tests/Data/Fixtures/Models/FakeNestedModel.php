<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Models;

use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Tests\Data\Fixtures\Factories\FakeNestedModelFactory;

class FakeNestedModel extends Model
{
    use HasFactory;

    protected array $guarded = [];

    protected array $casts = [
        'date' => 'immutable_datetime',
    ];

    /**
     * Get the parent fake model.
     */
    public function fakeModel(): BelongsTo
    {
        return $this->belongsTo(FakeModel::class);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): FakeNestedModelFactory
    {
        return FakeNestedModelFactory::new();
    }
}
