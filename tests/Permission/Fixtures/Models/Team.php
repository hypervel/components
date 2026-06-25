<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;

class Team extends Model
{
    protected array $fillable = ['name'];

    public bool $timestamps = false;

    protected ?string $table = 'teams';
}
