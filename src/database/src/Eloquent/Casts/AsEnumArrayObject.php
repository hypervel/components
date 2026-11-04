<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use BackedEnum;
use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use UnitEnum;

use function Hypervel\Support\enum_from;
use function Hypervel\Support\enum_value;

class AsEnumArrayObject implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @template TEnum of UnitEnum
     *
     * @param array{class-string<TEnum>} $arguments
     * @return CastsAttributes<ArrayObject<array-key, TEnum>, iterable<int|string|TEnum>>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class($arguments) implements CastsAttributes {
            protected array $arguments;

            /**
             * Create a new enum array object cast instance.
             */
            public function __construct(array $arguments)
            {
                $this->arguments = $arguments;
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

                if (! is_array($data)) {
                    return null;
                }

                $enumClass = $this->arguments[0];

                return new ArrayObject((new Collection($data))->map(function ($value) use ($enumClass) {
                    return is_subclass_of($enumClass, BackedEnum::class)
                        ? enum_from($enumClass, $value)
                        : constant($enumClass . '::' . $value);
                })->toArray());
            }

            /**
             * Transform the attribute to its underlying model values.
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): array
            {
                if ($value === null) {
                    return [$key => null];
                }

                $storable = [];

                foreach ($value as $enum) {
                    $storable[] = $this->getStorableEnumValue($enum);
                }

                $encoded = Json::encode($storable);

                if ($encoded === false) {
                    throw JsonEncodingException::forAttribute($model, $key, json_last_error_msg());
                }

                return [$key => $encoded];
            }

            /**
             * Determine if the assigned object can be cached as the attribute value.
             */
            public function shouldCacheObject(Model $model, string $key, object $value): bool
            {
                if (! $value instanceof ArrayObject) {
                    return false;
                }

                $enumClass = $this->arguments[0];

                foreach ($value as $item) {
                    if (! $item instanceof $enumClass) {
                        return false;
                    }
                }

                return true;
            }

            /**
             * Serialize the attribute for an array or JSON response.
             */
            public function serialize(Model $model, string $key, mixed $value, array $attributes): array
            {
                return (new Collection($value->getArrayCopy()))
                    ->map(fn ($enum) => $this->getStorableEnumValue($enum))
                    ->toArray();
            }

            /**
             * Get the storable value of the given enum.
             */
            protected function getStorableEnumValue(mixed $enum): string|int
            {
                if (is_string($enum) || is_int($enum)) {
                    return $enum;
                }

                return enum_value($enum);
            }
        };
    }

    /**
     * Specify the Enum for the cast.
     *
     * @param class-string $class
     */
    public static function of(string $class): string
    {
        return static::class . ':' . $class;
    }
}
