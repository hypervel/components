<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use BackedEnum;
use Hyperf\Database\Model\Castable;
use Hyperf\Database\Model\CastsAttributes;
use Hypervel\Support\Collection;

use function Hypervel\Support\enum_value;

class AsEnumCollection implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @template TEnum of \UnitEnum
     *
     * @param array{class-string<TEnum>} $arguments
     * @return CastsAttributes<Collection<array-key, TEnum>, iterable<TEnum>>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class($arguments) implements CastsAttributes
        {
            protected $arguments;

            public function __construct(array $arguments)
            {
                $this->arguments = $arguments;
            }

            public function get($model, $key, $value, $attributes)
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
                        ? $enumClass::from($value)
                        : constant($enumClass . '::' . $value);
                });
            }

            public function set($model, $key, $value, $attributes)
            {
                $value = $value !== null
                    ? Json::encode((new Collection($value))->map(function ($enum) {
                        return $this->getStorableEnumValue($enum);
                    })->jsonSerialize())
                    : null;

                return [$key => $value];
            }

            public function serialize($model, string $key, $value, array $attributes)
            {
                return (new Collection($value))
                    ->map(fn ($enum) => $this->getStorableEnumValue($enum))
                    ->toArray();
            }

            protected function getStorableEnumValue($enum)
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
