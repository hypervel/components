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

class AsEnumCollection implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @template TEnum of UnitEnum
     *
     * @param array{class-string<TEnum>} $arguments
     * @return CastsAttributes<Collection<array-key, TEnum>, iterable<int|string|TEnum>>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class($arguments) implements CastsAttributes {
            protected array $arguments;

            /**
             * Create a new enum collection cast instance.
             */
            public function __construct(array $arguments)
            {
                $this->arguments = $arguments;
            }

            /**
             * Transform the attribute from the underlying model values.
             */
            public function get(Model $model, string $key, mixed $value, array $attributes): ?Collection
            {
                if (! isset($attributes[$key])) {
                    return null;
                }

                $data = Json::decode($attributes[$key]);

                if (! is_array($data)) {
                    return null;
                }

                $enumClass = $this->arguments[0];

                return (new Collection($data))->map(function ($value) use ($enumClass) {
                    return is_subclass_of($enumClass, BackedEnum::class)
                        ? enum_from($enumClass, $value)
                        : constant($enumClass . '::' . $value);
                });
            }

            /**
             * Transform the attribute to its underlying model values.
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): array
            {
                $encoded = $value !== null
                    ? Json::encode((new Collection($value))->map(function ($enum) {
                        return $this->getStorableEnumValue($enum);
                    })->jsonSerialize())
                    : null;

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
                $enumClass = $this->arguments[0];

                return $value instanceof Collection && $value->every(fn ($item) => $item instanceof $enumClass);
            }

            /**
             * Serialize the attribute for an array or JSON response.
             */
            public function serialize(Model $model, string $key, mixed $value, array $attributes): array
            {
                return (new Collection($value))
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
