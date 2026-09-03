<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Events\ModelsPruned;
use LogicException;
use Throwable;

trait Prunable
{
    /**
     * Prune all prunable models in the database.
     */
    public function pruneAll(int $chunkSize = 1000): int
    {
        $total = 0;
        $events = null;

        $this->prunable()
            ->when(static::isSoftDeletable(), function ($query) {
                $query->withTrashed();
            })->chunkById($chunkSize, function ($models) use (&$events, &$total) {
                $models->each(function ($model) use (&$total) {
                    try {
                        $model->prune();

                        ++$total;
                    } catch (Throwable $e) {
                        $handler = app(ExceptionHandler::class);

                        if ($handler) {
                            $handler->report($e);
                        } else {
                            throw $e;
                        }
                    }
                });

                $events ??= app(Dispatcher::class);

                if ($events->hasListeners(ModelsPruned::class)) {
                    $events->dispatch(new ModelsPruned(static::class, $total));
                }
            });

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

    /**
     * Prune the model in the database.
     */
    public function prune(): int|bool|null
    {
        $this->pruning();

        return static::isSoftDeletable()
            ? $this->forceDelete()
            : $this->delete();
    }

    /**
     * Prepare the model for pruning.
     */
    protected function pruning(): void
    {
    }
}
