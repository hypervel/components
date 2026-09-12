<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models\IntTimestampCasts;

use Hypervel\Database\Eloquent\Model;

class UserWithIntTimestampsViaCasts extends Model
{
    protected ?string $table = 'users';

    protected array $fillable = ['email'];

    protected array $casts = [
        'created_at' => UnixTimeStampToCarbon::class,
        'updated_at' => UnixTimeStampToCarbon::class,
    ];
}
