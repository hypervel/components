<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Hypervel\Context\ApplicationContext;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Scope;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Events\ModelsFlushed;
use Hypervel\Scout\Events\ModelsImported;
use Hypervel\Support\Collection;
use Hypervel\Contracts\Event\Dispatcher;

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
            $chunkSize = $chunk ?? static::getScoutConfig('chunk.searchable', 500);

            $builder->chunkById($chunkSize, function (Collection $models) {
                /** @var EloquentCollection<int, Model&SearchableInterface> $models */
                /* @phpstan-ignore method.notFound (searchable() added via Searchable trait) */
                $models->filter(fn ($m) => $m->shouldBeSearchable())->searchable();

                static::dispatchEvent(new ModelsImported($models));
            }, $builder->qualifyColumn($scoutKeyName), $scoutKeyName);
        });

        $builder->macro('unsearchable', function (EloquentBuilder $builder, ?int $chunk = null) {
            /** @var Model&SearchableInterface $model */
            $model = $builder->getModel();
            $scoutKeyName = $model->getScoutKeyName();
            $chunkSize = $chunk ?? static::getScoutConfig('chunk.unsearchable', 500);

            $builder->chunkById($chunkSize, function (Collection $models) {
                /** @var EloquentCollection<int, Model&SearchableInterface> $models */
                /* @phpstan-ignore method.notFound (unsearchable() added via Searchable trait) */
                $models->unsearchable();

                static::dispatchEvent(new ModelsFlushed($models));
            }, $builder->qualifyColumn($scoutKeyName), $scoutKeyName);
        });
    }

    /**
     * Get a Scout configuration value.
     */
    protected static function getScoutConfig(string $key, mixed $default = null): mixed
    {
        return ApplicationContext::getContainer()
            ->get('config')
            ->get("scout.{$key}", $default);
    }

    /**
     * Dispatch an event through the event dispatcher.
     */
    protected static function dispatchEvent(object $event): void
    {
        ApplicationContext::getContainer()
            ->get(Dispatcher::class)
            ->dispatch($event);
    }
}
