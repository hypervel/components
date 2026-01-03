<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hyperf\Database\Model\Relations\MorphPivot as BaseMorphPivot;
use Hypervel\Database\Eloquent\Concerns\HasObservers;

class MorphPivot extends BaseMorphPivot
{
    use HasObservers;
}
