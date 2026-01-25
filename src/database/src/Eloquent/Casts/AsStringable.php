<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hyperf\Stringable\Stringable;

class AsStringable implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @return CastsAttributes<Stringable, string|\Stringable>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            public function get(mixed $model, string $key, mixed $value, array $attributes): ?Stringable
            {
                return isset($value) ? new Stringable($value) : null;
            }

            public function set(mixed $model, string $key, mixed $value, array $attributes): ?string
            {
                return isset($value) ? (string) $value : null;
            }
        };
    }
}
