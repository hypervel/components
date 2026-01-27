<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use BackedEnum;
use Hyperf\Contract\Arrayable;
use Stringable;
use UnitEnum;

use function Hypervel\Support\enum_value;

class Contains implements Stringable
{
    /**
     * The values that should be contained in the attribute.
     */
    protected array $values;

    /**
     * Create a new contains rule instance.
     */
    public function __construct(array|Arrayable|BackedEnum|string|UnitEnum $values)
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $this->values = is_array($values) ? $values : func_get_args();
    }

    /**
     * Convert the rule to a validation string.
     */
    public function __toString(): string
    {
        $values = array_map(function ($value) {
            $value = enum_value($value);

            return '"' . str_replace('"', '""', (string) $value) . '"';
        }, $this->values);

        return 'contains:' . implode(',', $values);
    }
}
