<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Fixtures\Pruning\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Prunable;

class PrunableTestModelWithoutPrunableRecords extends Model
{
    use Prunable;

    /**
     * Prune all prunable models in the database.
     */
    public function pruneAll(): int
    {
        return 0;
    }
}
