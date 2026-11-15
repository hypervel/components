<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Validation\ParameterizedRule;
use Hypervel\Validation\Membership;
use Hypervel\Validation\Rules\Concerns\FormatsMembershipRule;
use UnitEnum;

use function Hypervel\Support\enum_value;

class DoesntContain implements ParameterizedRule
{
    use FormatsMembershipRule;

    /**
     * The values that should not be contained in the attribute.
     */
    protected array $values;

    /**
     * Create a new doesnt_contain rule instance.
     */
    public function __construct(array|Arrayable|UnitEnum|string $values)
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $this->values = is_array($values) ? $values : func_get_args();
    }

    /**
     * Get the rule name followed by its parameters.
     */
    public function toArray(): array
    {
        return ['doesnt_contain', ...array_map(
            static fn (mixed $value): ?string => Membership::normalize(enum_value($value)),
            $this->values,
        )];
    }
}
