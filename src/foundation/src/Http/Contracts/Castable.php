<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Contracts;

use Hyperf\Contract\CastsAttributes;

interface Castable
{
    /**
     * Get the name of the caster class to use when casting from / to this cast target.
     */
    public static function castUsing(array $arguments = []): CastInputs|CastsAttributes|string;
}
