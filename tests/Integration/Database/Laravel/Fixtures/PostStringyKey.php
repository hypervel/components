<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\Fixtures;

use Hypervel\Database\Eloquent\Model;

class PostStringyKey extends Model
{
    protected ?string $table = 'my_posts';

    protected string $primaryKey = 'my_id';
}
