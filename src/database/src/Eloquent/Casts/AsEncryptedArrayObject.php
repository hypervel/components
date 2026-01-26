<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Support\Facades\Crypt;

class AsEncryptedArrayObject implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @return CastsAttributes<ArrayObject<array-key, mixed>, iterable>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes {
            public function get(mixed $model, string $key, mixed $value, array $attributes): ?ArrayObject
            {
                if (isset($attributes[$key])) {
                    return new ArrayObject(Json::decode(Crypt::decryptString($attributes[$key])));
                }

                return null;
            }

            public function set(mixed $model, string $key, mixed $value, array $attributes): ?array
            {
                if (! is_null($value)) {
                    return [$key => Crypt::encryptString(Json::encode($value))];
                }

                return null;
            }

            public function serialize(mixed $model, string $key, mixed $value, array $attributes): ?array
            {
                return ! is_null($value) ? $value->getArrayCopy() : null;
            }
        };
    }
}
