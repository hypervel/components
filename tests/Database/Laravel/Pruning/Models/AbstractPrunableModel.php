<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Pruning\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Prunable;

abstract class AbstractPrunableModel extends Model
{
    use Prunable;
}
