<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Pruning\Models;

use Hypervel\Database\Eloquent\MassPrunable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Events\ModelsPruned;

class PrunableTestModelWithPrunableRecords extends Model
{
    use MassPrunable;

    protected $table = 'prunables';

    protected $connection = 'default';

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
