<?php

declare(strict_types=1);

namespace Hypervel\Data\Http\Casts;

use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\DataCollection;
use Hypervel\Foundation\Http\Contracts\Castable;
use Hypervel\Foundation\Http\Contracts\CastInputs;
use InvalidArgumentException;

class AsDataCollection implements Castable, CastInputs
{
    /**
     * Create a FormRequest data collection cast.
     *
     * @param class-string<BaseData> $dataClass
     */
    public function __construct(
        protected readonly string $dataClass,
        protected readonly string $into = DataCollection::class,
    ) {
        if (! is_a($dataClass, BaseData::class, true)) {
            throw new InvalidArgumentException(
                "Data collection cast target `{$dataClass}` should implement `" . BaseData::class . '`',
            );
        }
    }

    /**
     * Get the cast declaration for a data collection.
     *
     * @param class-string<BaseData> $dataClass
     */
    public static function of(string $dataClass, string $into = DataCollection::class): string
    {
        return static::class . ':' . $dataClass . ',' . $into;
    }

    /**
     * Get the caster for a data collection.
     */
    public static function castUsing(array $arguments = []): CastInputs
    {
        $dataClass = $arguments[0] ?? throw new InvalidArgumentException(
            'A data class is required for the FormRequest data collection cast.',
        );

        return new static($dataClass, $arguments[1] ?? DataCollection::class);
    }

    /**
     * Transform an input value into a data collection.
     */
    public function get(string $key, mixed $value, array $inputs): mixed
    {
        if (! array_key_exists($key, $inputs) || $value === null) {
            return null;
        }

        return ($this->dataClass)::collect($value, $this->into);
    }
}
