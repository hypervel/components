<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hyperf\Database\Model\Relations\Pivot as BasePivot;
use Hypervel\Database\Eloquent\Concerns\HasObservers;

class Pivot extends BasePivot
{
    use HasObservers;
}
