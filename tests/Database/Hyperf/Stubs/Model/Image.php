<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs\Model;

class Image extends Model
{
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'images';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'url', 'imageable_id', 'imageable_type', 'created_at', 'updated_at'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'imageable_id' => 'integer', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function imageable()
    {
        return $this->morphTo();
    }
}
