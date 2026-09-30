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
        return new class($arguments) implements CastsAttributes {
            /**
             * Create a new array object cast instance.
             */
            public function __construct(protected array $arguments)
            {
            }

            /**
             * Transform the attribute from the underlying model values.
             */
            public function get(Model $model, string $key, mixed $value, array $attributes): ?ArrayObject
            {
                if (! isset($attributes[$key])) {
                    return null;
                }

                $data = Json::decode($attributes[$key]);

                return is_array($data) ? new ArrayObject($data, ArrayObject::ARRAY_AS_PROPS) : null;
            }

            /**
             * Transform the attribute to its underlying model values.
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): array
            {
                if (is_null($value) && in_array('nullable', $this->arguments, true)) {
                    return [$key => null];
                }

                $encoded = Json::encode($value);

                if ($encoded === false) {
                    throw JsonEncodingException::forAttribute($model, $key, json_last_error_msg());
                }

                return [$key => $encoded];
            }

            /**
             * Serialize the attribute for an array or JSON response.
             */
            public function serialize(Model $model, string $key, mixed $value, array $attributes): array
            {
                return $value->getArrayCopy();
            }
        };
    }

    /**
     * Specify that a null value assigned to the attribute should be persisted as a native SQL NULL instead of the JSON "null" literal.
     */
    public static function nullable(): string
    {
        return static::class . ':nullable';
    }
}
