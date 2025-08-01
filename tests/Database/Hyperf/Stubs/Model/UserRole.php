<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs\Model;

class UserRole extends Model
{
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'user_role';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'user_id', 'role_id', 'created_at', 'updated_at'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'user_id' => 'integer', 'role_id' => 'integer', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function users()
    {
        return $this->hasMany(User::class, 'id', 'user_id');
    }
}
