<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Casts;

use BackedEnum;
use Hypervel\Contracts\Http\CastsRequestInput;
use Hypervel\Contracts\Http\RequestCastable;
use Hypervel\Support\Collection;
use InvalidArgumentException;
use UnitEnum;

use function Hypervel\Support\enum_from;

class AsEnumCollection implements RequestCastable
{
    /**
     * Get the caster declaration for the given enum.
     *
     * @param class-string<UnitEnum> $enum
     */
    public static function of(string $enum): string
    {
        return static::class . ':' . $enum;
    }

    /**
     * Get the request input caster.
     *
     * @param string[] $arguments
     */
    public static function castRequestUsing(array $arguments): CastsRequestInput
    {
        $enum = $arguments[0] ?? throw new InvalidArgumentException(
            'An enum class is required for the FormRequest enum collection cast.',
        );

        return new class($enum) implements CastsRequestInput {
            /**
             * Create an enum collection caster.
             *
             * @param class-string<UnitEnum> $enum
             */
            public function __construct(protected string $enum)
            {
            }

            /**
             * Cast the given value to a collection of enums.
             */
            public function cast(string $key, mixed $value, array $input): ?Collection
            {
                if ($value === null) {
                    return null;
                }

                return (new Collection($value))->map(function (mixed $item): UnitEnum {
                    if ($item instanceof $this->enum) {
                        return $item;
                    }

                    return is_subclass_of($this->enum, BackedEnum::class)
                        ? enum_from($this->enum, $item)
                        : constant($this->enum . '::' . $item);
                });
            }
        };
    }
}
