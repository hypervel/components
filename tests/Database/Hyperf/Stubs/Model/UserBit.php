<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs\Model;

class UserBit extends Model
{
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'user_bit';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'bit', 'created_at', 'updated_at'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
}
