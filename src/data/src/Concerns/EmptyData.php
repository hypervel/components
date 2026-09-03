<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Hypervel\Container\Container;
use Hypervel\Data\Support\Transformation\EmptyDataResolver;
use Hypervel\Support\Arr;

trait EmptyData
{
    /**
     * Create an empty representation of the data class.
     */
    public static function empty(
        array $extra = [],
        mixed $replaceNullValuesWith = null,
        ?array $except = null,
        ?array $only = null,
    ): array {
        $emptyData = Container::getInstance()
            ->make(EmptyDataResolver::class)
            ->execute(static::class, $extra, $replaceNullValuesWith);

        if ($only !== null) {
            $emptyData = Arr::only($emptyData, $only);
        }

        if ($except !== null) {
            $emptyData = Arr::except($emptyData, $except);
        }

        return $emptyData;
    }
}
