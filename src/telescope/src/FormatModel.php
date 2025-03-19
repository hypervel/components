<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use BackedEnum;
use Hyperf\Collection\Arr;
use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\Pivot;

class FormatModel
{
    /**
     * Format the given model to a readable string.
     */
    public static function given(Model $model): string
    {
        if ($model instanceof Pivot && ! $model->incrementing) {
            $keys = [
                $model->getAttribute($model->getForeignKey()),
                $model->getAttribute($model->getRelatedKey()),
            ];
        } else {
            $keys = $model->getKey();
        }

        return get_class($model) . ':' . implode('_', array_map(function ($value) {
            return $value instanceof BackedEnum ? $value->value : $value;
        }, Arr::wrap($keys)));
    }
}
