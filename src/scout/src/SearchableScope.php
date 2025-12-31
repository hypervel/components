<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Model\Builder as HyperfBuilder;
use Hyperf\Database\Model\Model as HyperfModel;
use Hyperf\Database\Model\Scope;
use Hypervel\Context\ApplicationContext;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Events\ModelsFlushed;
use Hypervel\Scout\Events\ModelsImported;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Global scope that adds batch search macros to the query builder.
 */
class SearchableScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(HyperfBuilder $builder, HyperfModel $model): void
    {
        // This scope doesn't modify queries, only extends the builder
    }

    /**
     * Extend the query builder with the needed functions.
     */
    public function extend(EloquentBuilder $builder): void
    {
        $builder->macro('searchable', function (EloquentBuilder $builder, ?int $chunk = null) {
            $scoutKeyName = $builder->getModel()->getScoutKeyName();
            $chunkSize = $chunk ?? static::getScoutConfig('chunk.searchable', 500);

            $builder->chunkById($chunkSize, function ($models) {
                $models->filter->shouldBeSearchable()->searchable();

                static::dispatchEvent(new ModelsImported($models));
            }, $builder->qualifyColumn($scoutKeyName), $scoutKeyName);
        });

        $builder->macro('unsearchable', function (EloquentBuilder $builder, ?int $chunk = null) {
            $scoutKeyName = $builder->getModel()->getScoutKeyName();
            $chunkSize = $chunk ?? static::getScoutConfig('chunk.unsearchable', 500);

            $builder->chunkById($chunkSize, function ($models) {
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
            ->get(ConfigInterface::class)
            ->get("scout.{$key}", $default);
    }

    /**
     * Dispatch an event through the event dispatcher.
     */
    protected static function dispatchEvent(object $event): void
    {
        ApplicationContext::getContainer()
            ->get(EventDispatcherInterface::class)
            ->dispatch($event);
    }
}
