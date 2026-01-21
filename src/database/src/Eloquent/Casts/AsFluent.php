<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Database\Contracts\Eloquent\Castable;
use Hypervel\Database\Contracts\Eloquent\CastsAttributes;
use Hyperf\Support\Fluent;

class AsFluent implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @return CastsAttributes<Fluent, string>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            public function get(mixed $model, string $key, mixed $value, array $attributes): ?Fluent
            {
                return isset($value) ? new Fluent(Json::decode($value)) : null;
            }

            public function set(mixed $model, string $key, mixed $value, array $attributes): ?array
            {
                return isset($value) ? [$key => Json::encode($value)] : null;
            }
        };
    }
}
