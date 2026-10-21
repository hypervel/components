<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Database\Eloquent;

use Hypervel\Contracts\Database\Query\Builder as BaseContract;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;

/**
 * This interface is intentionally empty and exists to improve IDE support.
 *
 * @mixin EloquentBuilder
 */
interface Builder extends BaseContract
{
}
