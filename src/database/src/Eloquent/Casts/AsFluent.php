<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Fluent;

class AsFluent implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @return CastsAttributes<Fluent, string>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes {
            public function get(Model $model, string $key, mixed $value, array $attributes): ?Fluent
            {
                if (! isset($value)) {
                    return null;
                }

                $data = Json::decode($value);

                // Custom decoders may return objects, which Fluent supports alongside arrays.
                return is_array($data) || is_object($data) ? new Fluent($data) : null;
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): ?array
            {
                if (! isset($value)) {
                    return null;
                }

                $encoded = Json::encode($value);

                if ($encoded === false) {
                    throw JsonEncodingException::forAttribute($model, $key, json_last_error_msg());
                }

                return [$key => $encoded];
            }
        };
    }
}
