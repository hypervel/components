<?php

declare(strict_types=1);

namespace Hypervel\Data\Http\Casts;

use Hypervel\Contracts\Http\CastsRequestInput;
use Hypervel\Contracts\Http\RequestCastable;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\DataCollection;
use InvalidArgumentException;

class AsDataCollection implements CastsRequestInput, RequestCastable
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
     * Get the request caster for a data collection.
     *
     * @param string[] $arguments
     */
    public static function castRequestUsing(array $arguments): CastsRequestInput
    {
        $dataClass = $arguments[0] ?? throw new InvalidArgumentException(
            'A data class is required for the FormRequest data collection cast.',
        );

        return new static($dataClass, $arguments[1] ?? DataCollection::class);
    }

    /**
     * Cast an input value to a data collection.
     */
    public function cast(string $key, mixed $value, array $input): mixed
    {
        if ($value === null) {
            return null;
        }

        return ($this->dataClass)::collect($value, $this->into);
    }
}
