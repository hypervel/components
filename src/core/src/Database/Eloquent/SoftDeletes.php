<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hyperf\Database\Model\SoftDeletes as HyperfSoftDeletes;

trait SoftDeletes
{
    use HyperfSoftDeletes;
}
