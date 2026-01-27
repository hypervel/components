<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Pruning\Models;

use Hypervel\Database\Eloquent\Prunable;

trait NonPrunableTrait
{
    use Prunable;
}
