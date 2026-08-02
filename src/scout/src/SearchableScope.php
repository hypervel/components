<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Scope;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Events\ModelsFlushed;
use Hypervel\Scout\Events\ModelsImported;
use Hypervel\Support\Collection;

/**
 * Global scope that adds batch search macros to the query builder.
 */
class SearchableScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(EloquentBuilder $builder, Model $model): void
    {
        // This scope doesn't modify queries, only extends the builder
    }

    /**
     * Extend the query builder with the needed functions.
     */
    public function extend(EloquentBuilder $builder): void
    {
        $builder->macro('searchable', function (EloquentBuilder $builder, ?int $chunk = null) {
            /** @var Model&SearchableInterface $model */
            $model = $builder->getModel();
            $scoutKeyName = $model->getScoutKeyName();
            $chunkSize = $chunk ?? config('scout.chunk.searchable', 500);

            $builder->chunkById($chunkSize, function (Collection $models) {
                /** @var EloquentCollection<int, Model&SearchableInterface> $models */
                /* @phpstan-ignore method.notFound (searchable() added via Searchable trait) */
                $models->filter(fn ($m) => $m->shouldBeSearchable())->searchable();

                // @phpstan-ignore staticMethod.notFound (local macros retain their lexical class scope at runtime)
                static::dispatchEvent(ModelsImported::class, $models);
                Scout::reportImportProgress($models);
            }, $builder->qualifyColumn($scoutKeyName), $scoutKeyName);
        });

        $builder->macro('unsearchable', function (EloquentBuilder $builder, ?int $chunk = null) {
            /** @var Model&SearchableInterface $model */
            $model = $builder->getModel();
            $scoutKeyName = $model->getScoutKeyName();
            $chunkSize = $chunk ?? config('scout.chunk.unsearchable', 500);

            $builder->chunkById($chunkSize, function (Collection $models) {
                /** @var EloquentCollection<int, Model&SearchableInterface> $models */
                /* @phpstan-ignore method.notFound (unsearchable() added via Searchable trait) */
                $models->unsearchable();

                // @phpstan-ignore staticMethod.notFound (local macros retain their lexical class scope at runtime)
                static::dispatchEvent(ModelsFlushed::class, $models);
            }, $builder->qualifyColumn($scoutKeyName), $scoutKeyName);
        });
    }

    /**
     * Dispatch an event when the dispatcher has listeners for it.
     *
     * @param class-string<ModelsFlushed|ModelsImported> $event
     */
    protected static function dispatchEvent(string $event, Collection $models): void
    {
        $events = Container::getInstance()->make(Dispatcher::class);

        if ($events->hasListeners($event)) {
            $events->dispatch(new $event($models));
        }
    }
}
