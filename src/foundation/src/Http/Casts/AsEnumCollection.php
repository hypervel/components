<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Casts;

use BackedEnum;
use Hypervel\Foundation\Http\Contracts\Castable;
use Hypervel\Foundation\Http\Contracts\CastInputs;
use Hypervel\Support\Collection;

class AsEnumCollection implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @param string $class The enum class name
     */
    public static function of(string $class): string
    {
        return static::class . ':' . $class;
    }

    /**
     * Get the caster class to use when casting from / to this cast target.
     */
    public static function castUsing(array $arguments = []): CastInputs
    {
        return new class($arguments) implements CastInputs {
            public function __construct(protected array $arguments)
            {
            }

            public function get(string $key, mixed $value, array $inputs): mixed
            {
                if (! isset($inputs[$key]) || ! is_array($value)) {
                    return null;
                }

                $enumClass = $this->arguments[0];

                return (new Collection($value))->map(function ($item) use ($enumClass) {
                    if ($item instanceof $enumClass) {
                        return $item;
                    }

                    return is_subclass_of($enumClass, BackedEnum::class)
                        ? $enumClass::from($item)
                        : constant($enumClass . '::' . $item);
                });
            }
        };
    }
}
