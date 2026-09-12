<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models\Guarded;

use Hypervel\Database\Eloquent\Model;

class Post extends Model
{
    protected array $guarded = [];
}
