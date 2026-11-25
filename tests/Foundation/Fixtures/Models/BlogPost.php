<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;

class BlogPost extends Model
{
    protected array $guarded = [];

    public bool $timestamps = false;
}
