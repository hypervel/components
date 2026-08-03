<?php

declare(strict_types=1);

namespace Hypervel\Scout\Jobs;

use Hypervel\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Hypervel\Scout\Traits\UniqueByScoutKeys;

class RemoveFromSearchUniquely extends RemoveFromSearch implements ShouldBeUniqueUntilProcessing
{
    use UniqueByScoutKeys;
}
