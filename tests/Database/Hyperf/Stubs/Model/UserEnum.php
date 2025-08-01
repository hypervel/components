<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs\Model;

class UserEnum extends Model
{
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'user';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'name', 'gender', 'created_at', 'updated_at'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'gender' => Gender::class, 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function book()
    {
        var_dump(1);
        return $this->hasOne(Book::class, 'user_id', 'id'); // ignore
    }
}
