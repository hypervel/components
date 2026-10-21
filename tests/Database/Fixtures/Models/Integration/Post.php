<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Fixtures\Models\Integration;

use Hypervel\Database\Eloquent\Model;

class Post extends Model
{
    protected ?string $table = 'posts';
}
