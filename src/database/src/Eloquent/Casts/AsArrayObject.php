<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Database\Contracts\Eloquent\Castable;
use Hypervel\Database\Contracts\Eloquent\CastsAttributes;

class AsArrayObject implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @return CastsAttributes<ArrayObject<array-key, mixed>, iterable>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            public function get(mixed $model, string $key, mixed $value, array $attributes): ?ArrayObject
            {
                if (! isset($attributes[$key])) {
                    return null;
                }

                $data = Json::decode($attributes[$key]);

                return is_array($data) ? new ArrayObject($data, ArrayObject::ARRAY_AS_PROPS) : null;
            }

            public function set(mixed $model, string $key, mixed $value, array $attributes): array
            {
                return [$key => Json::encode($value)];
            }

            public function serialize(mixed $model, string $key, mixed $value, array $attributes): array
            {
                return $value->getArrayCopy();
            }
        };
    }
}
