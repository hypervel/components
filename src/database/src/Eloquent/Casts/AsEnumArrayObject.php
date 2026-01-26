<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use BackedEnum;
use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Support\Collection;

use function Hypervel\Support\enum_value;

class AsEnumArrayObject implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @template TEnum of \UnitEnum
     *
     * @param array{class-string<TEnum>} $arguments
     * @return CastsAttributes<ArrayObject<array-key, TEnum>, iterable<TEnum>>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class($arguments) implements CastsAttributes {
            protected array $arguments;

            public function __construct(array $arguments)
            {
                $this->arguments = $arguments;
            }

            public function get(mixed $model, string $key, mixed $value, array $attributes): ?ArrayObject
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
                        ? $enumClass::from($value)
                        : constant($enumClass . '::' . $value);
                })->toArray());
            }

            public function set(mixed $model, string $key, mixed $value, array $attributes): array
            {
                if ($value === null) {
                    return [$key => null];
                }

                $storable = [];

                foreach ($value as $enum) {
                    $storable[] = $this->getStorableEnumValue($enum);
                }

                return [$key => Json::encode($storable)];
            }

            public function serialize(mixed $model, string $key, mixed $value, array $attributes): array
            {
                return (new Collection($value->getArrayCopy()))
                    ->map(fn ($enum) => $this->getStorableEnumValue($enum))
                    ->toArray();
            }

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
