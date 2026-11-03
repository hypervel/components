<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Facades\Crypt;
use JsonSerializable;
use Traversable;

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
            /**
             * Transform the attribute from the underlying model values.
             */
            public function get(Model $model, string $key, mixed $value, array $attributes): ?ArrayObject
            {
                if (isset($attributes[$key])) {
                    $data = Json::decode(Crypt::decryptString($attributes[$key]));

                    return is_array($data) ? new ArrayObject($data, ArrayObject::ARRAY_AS_PROPS) : null;
                }

                return null;
            }

            /**
             * Transform the attribute to its underlying model values.
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): ?array
            {
                if (! is_null($value)) {
                    // JSON encoding a plain Traversable such as a generator would discard its items.
                    $encoded = Json::encode(
                        $value instanceof Traversable && ! $value instanceof JsonSerializable ? iterator_to_array($value) : $value
                    );

                    if ($encoded === false) {
                        throw JsonEncodingException::forAttribute($model, $key, json_last_error_msg());
                    }

                    return [$key => Crypt::encryptString($encoded)];
                }

                return null;
            }

            /**
             * Determine if the assigned object can be cached as the attribute value.
             */
            public function shouldCacheObject(Model $model, string $key, object $value): bool
            {
                return $value instanceof ArrayObject;
            }

            /**
             * Serialize the attribute for an array or JSON response.
             */
            public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
            {
                return ! is_null($value) ? $value->getArrayCopy() : null;
            }
        };
    }
}
