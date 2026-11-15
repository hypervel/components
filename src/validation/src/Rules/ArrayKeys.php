<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Validation\ParameterizedRule;
use Hypervel\Validation\Rules\Concerns\FormatsMembershipRule;
use UnitEnum;

use function Hypervel\Support\enum_value;

class ArrayKeys implements ParameterizedRule
{
    use FormatsMembershipRule;

    /**
     * The accepted keys.
     */
    protected array $keys;

    /**
     * Create a new array keys rule instance.
     */
    public function __construct(array|Arrayable|UnitEnum|int|string $keys)
    {
        if ($keys instanceof Arrayable) {
            $keys = $keys->toArray();
        }

        $this->keys = is_array($keys) ? $keys : func_get_args();
    }

    /**
     * Get the rule name followed by its parameters.
     */
    public function toArray(): array
    {
        return ['array_keys', ...array_map(
            static fn (mixed $value): string => (string) enum_value($value),
            $this->keys,
        )];
    }
}
