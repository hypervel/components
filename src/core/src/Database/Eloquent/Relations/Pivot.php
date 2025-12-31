<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hyperf\Database\Model\Relations\Pivot as BasePivot;
use Hypervel\Database\Eloquent\Concerns\HasGlobalScopes;

class Pivot extends BasePivot
{
    use HasGlobalScopes;
}
