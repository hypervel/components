<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Support\Collection;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Concerns\AsPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\ModelIdentifier;
use Hypervel\Queue\Contracts\QueueableCollection;
use Hypervel\Queue\Contracts\QueueableEntity;

trait SerializesAndRestoresModelIdentifiers
{
    /**
     * Get the property value prepared for serialization.
     */
    protected function getSerializedPropertyValue(mixed $value, bool $withRelations = true): mixed
    {
        if ($value instanceof QueueableCollection) {
            return (new ModelIdentifier(
                $value->getQueueableClass(),
                $value->getQueueableIds(),
                $withRelations ? $value->getQueueableRelations() : [],
                $value->getQueueableConnection()
            ))->useCollectionClass(
                ($collectionClass = get_class($value)) !== EloquentCollection::class
                    ? $collectionClass
                    : null
            );
        }

        if ($value instanceof QueueableEntity) {
            return new ModelIdentifier(
                get_class($value),
                $value->getQueueableId(),
                $withRelations ? $value->getQueueableRelations() : [],
                $value->getQueueableConnection()
            );
        }

        return $value;
    }

    /**
     * Get the restored property value after deserialization.
     */
    protected function getRestoredPropertyValue(mixed $value): mixed
    {
        if (! $value instanceof ModelIdentifier) {
            return $value;
        }

        return is_array($value->id)
            ? $this->restoreCollection($value)
            : $this->restoreModel($value);
    }

    /**
     * Restore a queueable collection instance.
     */
    protected function restoreCollection(ModelIdentifier $value): Collection
    {
        if (! $value->class || count($value->id) === 0) {
            return ! is_null($value->collectionClass ?? null)
                ? new $value->collectionClass()
                : new EloquentCollection();
        }

        $collection = $this->getQueryForModelRestoration(
            (new $value->class())->setConnection($value->connection),
            $value->id
        )->useWritePdo()->get();

        if (is_a($value->class, Pivot::class, true)
            || in_array(AsPivot::class, class_uses($value->class))
        ) {
            return $collection;
        }

        /* @phpstan-ignore-next-line */
        $collection = $collection->keyBy->getKey();

        $collectionClass = get_class($collection);

        return new $collectionClass(
            Collection::make($value->id)->map(function ($id) use ($collection) {
                return $collection[$id] ?? null;
            })->filter()
        );
    }

    /**
     * Restore the model from the model identifier instance.
     */
    public function restoreModel(ModelIdentifier $value): Model
    {
        return $this->getQueryForModelRestoration(
            (new $value->class())->setConnection($value->connection),
            $value->id
        )->useWritePdo()->firstOrFail()->load($value->relations ?? []);
    }

    /**
     * Get the query for model restoration.
     */
    protected function getQueryForModelRestoration(Model $model, array|int $ids): Builder
    {
        return $model->newQueryForRestoration($ids);
    }
}
