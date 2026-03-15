<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hypervel\Database\Events\ModelsPruned;
use LogicException;

trait MassPrunable
{
    /**
     * Prune all prunable models in the database.
     */
    public function pruneAll(int $chunkSize = 1000): int
    {
        $query = tap($this->prunable(), function ($query) use ($chunkSize) {
            $query->when(! $query->getQuery()->limit, function ($query) use ($chunkSize) {
                $query->limit($chunkSize);
            });
        });

        $total = 0;

        $softDeletable = static::isSoftDeletable();

        do {
            $total += $count = $softDeletable
                ? $query->forceDelete()
                : $query->delete();

            if ($count > 0) {
                event(new ModelsPruned(static::class, $total));
            }
        } while ($count > 0);

        return $total;
    }

    /**
     * Get the prunable model query.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        throw new LogicException('Please implement the prunable method on your model.');
    }
}
