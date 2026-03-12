<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Pruning\Models;

use Hypervel\Database\Eloquent\MassPrunable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Events\ModelsPruned;
use UnitEnum;

class PrunableTestModelWithPrunableRecords extends Model
{
    use MassPrunable;

    protected ?string $table = 'prunables';

    protected UnitEnum|string|null $connection = 'default';

    public function pruneAll()
    {
        event(new ModelsPruned(static::class, 10));
        event(new ModelsPruned(static::class, 20));

        return 20;
    }

    public function prunable()
    {
        return static::where('value', '>=', 3);
    }
}
