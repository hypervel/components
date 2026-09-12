<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Fixtures\Pruning\Models;

use Hypervel\Database\Eloquent\Builder;
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

    /**
     * Get the prunable model query.
     */
    public function prunable(): Builder
    {
        return static::where('value', '>=', 3);
    }
}
