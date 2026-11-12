<?php

declare(strict_types=1);

namespace App\Traits;

use Hypervel\Database\Eloquent\SoftDeletes;

trait NestedSoftDeletes
{
    use SoftDeletes;
}
