<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hyperf\Database\Model\Castable;
use Hyperf\Database\Model\CastsAttributes;
use Hyperf\Stringable\Stringable;

class AsStringable implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @param array $arguments
     * @return CastsAttributes<Stringable, string|\Stringable>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            public function get($model, $key, $value, $attributes)
            {
                return isset($value) ? new Stringable($value) : null;
            }

            public function set($model, $key, $value, $attributes)
            {
                return isset($value) ? (string) $value : null;
            }
        };
    }
}
