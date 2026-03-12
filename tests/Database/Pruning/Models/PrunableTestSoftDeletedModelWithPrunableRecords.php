<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Pruning\Models;

use Hypervel\Database\Eloquent\MassPrunable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use UnitEnum;

class PrunableTestSoftDeletedModelWithPrunableRecords extends Model
{
    use MassPrunable;
    use SoftDeletes;

    protected ?string $table = 'prunables';

    protected UnitEnum|string|null $connection = 'default';

    public function prunable()
    {
        return static::where('value', '>=', 3);
    }
}
