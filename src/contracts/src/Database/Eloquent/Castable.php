<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Database\Eloquent;

interface Castable
{
    /**
     * Get the name of the caster class to use when casting from / to this cast target.
     *
     * @param string[] $arguments
     * @return CastsAttributes|CastsInboundAttributes|class-string<CastsAttributes|CastsInboundAttributes>
     */
    public static function castUsing(array $arguments);
}
