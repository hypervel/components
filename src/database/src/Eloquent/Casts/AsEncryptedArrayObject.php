<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\Model;
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
            public function get(Model $model, string $key, mixed $value, array $attributes): ?ArrayObject
            {
                if (isset($attributes[$key])) {
                    $data = Json::decode(Crypt::decryptString($attributes[$key]));

                    return is_array($data) ? new ArrayObject($data, ArrayObject::ARRAY_AS_PROPS) : null;
                }

                return null;
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): ?array
            {
                if (! is_null($value)) {
                    $encoded = Json::encode($value);

                    if ($encoded === false) {
                        throw JsonEncodingException::forAttribute($model, $key, json_last_error_msg());
                    }

                    return [$key => Crypt::encryptString($encoded)];
                }

                return null;
            }

            public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
            {
                return ! is_null($value) ? $value->getArrayCopy() : null;
            }
        };
    }
}
