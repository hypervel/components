<?php

declare(strict_types=1);

namespace App;

use App\Casts\FormattedDate;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;

class ModelWithDateColumnCasts extends Model
{
    use SoftDeletes;

    protected ?string $table = 'model_with_casts';

    protected array $casts = [
        'created_at' => FormattedDate::class,
        'updated_at' => 'timestamp',
        'deleted_at' => 'immutable_datetime',
    ];
}
