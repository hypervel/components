<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Uri;

class AsUri implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @return CastsAttributes<Uri, string|Uri>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes {
            /**
             * Cast the stored value to a URI.
             */
            public function get(Model $model, string $key, mixed $value, array $attributes): ?Uri
            {
                return isset($value) ? new Uri($value) : null;
            }

            /**
             * Prepare the URI for storage.
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): ?string
            {
                return isset($value) ? (string) $value : null;
            }
        };
    }
}
