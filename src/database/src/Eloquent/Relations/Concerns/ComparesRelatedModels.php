<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations\Concerns;

use Hypervel\Contracts\Database\Eloquent\SupportsPartialRelations;
use Hypervel\Database\Eloquent\Model;

trait ComparesRelatedModels
{
    /**
     * Determine if the model is the related instance of the relationship.
     */
    public function is(?Model $model): bool
    {
        $match = ! is_null($model)
            && $this->compareKeys($this->getParentKey(), $this->getRelatedKeyFrom($model))
            && $this->related->getTable() === $model->getTable()
            && $this->related->getConnectionName() === $model->getConnectionName();

        if ($match && $this instanceof SupportsPartialRelations && $this->isOneOfMany()) {
            return $this->query
                ->whereKey($model->getKey())
                ->exists();
        }

        return $match;
    }

    /**
     * Determine if the model is not the related instance of the relationship.
     */
    public function isNot(?Model $model): bool
    {
        return ! $this->is($model);
    }

    /**
     * Get the value of the parent model's key.
     */
    abstract public function getParentKey(): mixed;

    /**
     * Get the value of the model's related key.
     */
    abstract protected function getRelatedKeyFrom(Model $model): mixed;

    /**
     * Compare the parent key with the related key.
     */
    protected function compareKeys(mixed $parentKey, mixed $relatedKey): bool
    {
        if (empty($parentKey) || empty($relatedKey)) {
            return false;
        }

        if (is_int($parentKey) || is_int($relatedKey)) {
            return (int) $parentKey === (int) $relatedKey;
        }

        return $parentKey === $relatedKey;
    }
}
