<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Events\ModelsPruned;
use LogicException;

trait MassPrunable
{
    /**
     * Prune all prunable models in the database.
     */
    public function pruneAll(int $chunkSize = 1000): int
    {
        $softDeletable = static::isSoftDeletable();

        $query = tap($this->prunable(), function (Builder $query) use ($chunkSize, $softDeletable): void {
            $query->when($softDeletable, fn (Builder $query): Builder => $query->withTrashed())
                ->when(! $query->getQuery()->limit, fn (Builder $query): Builder => $query->limit($chunkSize));
        });

        $total = 0;
        $events = null;

        do {
            $total += $count = $softDeletable
                ? $query->forceDelete()
                : $query->delete();

            if ($count > 0) {
                $events ??= app(Dispatcher::class);

                if ($events->hasListeners(ModelsPruned::class)) {
                    $events->dispatch(new ModelsPruned(static::class, $total));
                }
            }
        } while ($count > 0);

        return $total;
    }

    /**
     * Get the prunable model query.
     *
     * @return Builder<static>
     *
     * @throws LogicException
     */
    public function prunable(): Builder
    {
        throw new LogicException('Please implement the prunable method on your model.');
    }
}
