<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Contracts\Database\Eloquent\CastsInboundAttributes;
use Hypervel\Data\Eloquent\DataEloquentCast;

trait EloquentCastableData
{
    /**
     * Get the Eloquent caster for the data object.
     */
    public static function castUsing(array $arguments): CastsAttributes|CastsInboundAttributes|string
    {
        return new DataEloquentCast(static::class, $arguments);
    }
}
