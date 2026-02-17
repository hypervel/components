<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Support\Arrayable;
use Stringable;
use UnitEnum;

use function Hypervel\Support\enum_value;

class NotIn implements Stringable
{
    /**
     * The name of the rule.
     */
    protected string $rule = 'not_in';

    /**
     * The accepted values.
     */
    protected array $values;

    /**
     * Create a new "not in" rule instance.
     *
     * @param array|Arrayable|string|UnitEnum $values
     */
    public function __construct($values)
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

        return $this->rule . ':' . implode(',', $values);
    }
}
