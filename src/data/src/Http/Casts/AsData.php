<?php

declare(strict_types=1);

namespace Hypervel\Data\Http\Casts;

use Hypervel\Data\Contracts\BaseData;
use Hypervel\Foundation\Http\Contracts\Castable;
use Hypervel\Foundation\Http\Contracts\CastInputs;
use InvalidArgumentException;

class AsData implements Castable, CastInputs
{
    /**
     * Create a FormRequest data cast.
     *
     * @param class-string<BaseData> $dataClass
     */
    public function __construct(protected readonly string $dataClass)
    {
        if (! is_a($dataClass, BaseData::class, true)) {
            throw new InvalidArgumentException(
                "Data cast target `{$dataClass}` should implement `" . BaseData::class . '`',
            );
        }
    }

    /**
     * Get the cast declaration for a data class.
     *
     * @param class-string<BaseData> $dataClass
     */
    public static function of(string $dataClass): string
    {
        return static::class . ':' . $dataClass;
    }

    /**
     * Get the caster for a data class.
     */
    public static function castUsing(array $arguments = []): CastInputs
    {
        $dataClass = $arguments[0] ?? throw new InvalidArgumentException(
            'A data class is required for the FormRequest data cast.',
        );

        return new static($dataClass);
    }

    /**
     * Transform an input value into data.
     */
    public function get(string $key, mixed $value, array $inputs): ?BaseData
    {
        if (! array_key_exists($key, $inputs) || $value === null) {
            return null;
        }

        return ($this->dataClass)::from($value);
    }
}
