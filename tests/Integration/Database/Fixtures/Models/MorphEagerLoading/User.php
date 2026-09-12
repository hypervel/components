<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\Models\MorphEagerLoading;

use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    public bool $timestamps = false;
}
