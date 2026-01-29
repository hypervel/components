<?php

declare(strict_types=1);

namespace Illuminate\Tests\Integration\Database\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    public $table = 'posts';
}
