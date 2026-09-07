<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\Model;

class AsArrayObject implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @return CastsAttributes<ArrayObject<array-key, mixed>, iterable>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes {
            public function get(Model $model, string $key, mixed $value, array $attributes): ?ArrayObject
            {
                if (! isset($attributes[$key])) {
                    return null;
                }

                $data = Json::decode($attributes[$key]);

                return is_array($data) ? new ArrayObject($data, ArrayObject::ARRAY_AS_PROPS) : null;
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): array
            {
                $encoded = Json::encode($value);

                if ($encoded === false) {
                    throw JsonEncodingException::forAttribute($model, $key, json_last_error_msg());
                }

                return [$key => $encoded];
            }

            public function serialize(Model $model, string $key, mixed $value, array $attributes): array
            {
                return $value->getArrayCopy();
            }
        };
    }
}
