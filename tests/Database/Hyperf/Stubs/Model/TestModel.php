<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs\Model;

class TestModel extends Model
{
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'test';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'user_id', 'uid', 'created_at', 'updated_at'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'user_id' => 'integer', 'uid' => 'integer', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function image()
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
