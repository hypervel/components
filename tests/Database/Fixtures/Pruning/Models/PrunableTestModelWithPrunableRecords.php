<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Fixtures\Pruning\Models;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\MassPrunable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Events\ModelsPruned;
use UnitEnum;

class PrunableTestModelWithPrunableRecords extends Model
{
    use MassPrunable;

    protected ?string $table = 'prunables';

    protected UnitEnum|string|null $connection = 'default';

    /**
     * Prune all prunable models in the database.
     */
    public function pruneAll(): int
    {
        event(new ModelsPruned(static::class, 10));
        event(new ModelsPruned(static::class, 20));

        return 20;
    }

    /**
     * Get the prunable model query.
     */
    public function prunable(): Builder
    {
        return static::where('value', '>=', 3);
    }
}
