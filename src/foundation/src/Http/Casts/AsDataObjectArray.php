<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Casts;

use ArrayObject;
use Hypervel\Foundation\Http\Contracts\Castable;
use Hypervel\Foundation\Http\Contracts\CastInputs;

class AsDataObjectArray implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @param string $class The data object class name
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

                $dataClass = $this->arguments[0];

                // Check if the class has make static method (provided by DataObject)
                if (! method_exists($dataClass, 'make')) {
                    throw new \RuntimeException(
                        "Class {$dataClass} must implement static make(array \$data) method"
                    );
                }

                return new ArrayObject(
                    array_map(fn ($item) => $dataClass::make($item), $value)
                );
            }

            public function set(string $key, mixed $value, array $inputs): array
            {
                if ($value === null) {
                    return [$key => null];
                }

                $storable = array_map(function ($item) {
                    if (method_exists($item, 'toArray')) {
                        return $item->toArray();
                    }
                    return $item;
                }, (array) $value);

                return [$key => $storable];
            }
        };
    }
}