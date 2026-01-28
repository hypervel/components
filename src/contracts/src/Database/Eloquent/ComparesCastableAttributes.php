<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Database\Eloquent;

use Hypervel\Database\Eloquent\Model;

interface ComparesCastableAttributes
{
    /**
     * Determine if the given values are equal.
     */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool;
}
