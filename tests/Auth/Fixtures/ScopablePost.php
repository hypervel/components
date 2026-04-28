<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Fixtures;

use Hypervel\Database\Eloquent\Model;

class ScopablePost extends Model
{
    protected ?string $table = 'posts';
}
