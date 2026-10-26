<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Stringable;
use Stringable as BaseStringable;

class AsStringable implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @return CastsAttributes<Stringable, BaseStringable|string>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes {
            /**
             * Cast the stored value to a stringable instance.
             */
            public function get(Model $model, string $key, mixed $value, array $attributes): ?Stringable
            {
                return isset($value) ? new Stringable($value) : null;
            }

            /**
             * Prepare the stringable value for storage.
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): ?string
            {
                return isset($value) ? (string) $value : null;
            }
        };
    }
}
