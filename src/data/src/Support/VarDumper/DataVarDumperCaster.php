<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\VarDumper;

use Hypervel\Data\Contracts\BaseDataCollectable;
use Hypervel\Data\Contracts\TransformableData;
use Symfony\Component\VarDumper\Cloner\Stub;

class DataVarDumperCaster
{
    /**
     * Cast transformable data to its current logical view.
     */
    public static function cast(
        TransformableData $data,
        array $properties,
        Stub $stub,
        bool $isNested,
    ): array {
        return $data instanceof BaseDataCollectable
            ? ['items' => $data->all()]
            : $data->all();
    }
}
