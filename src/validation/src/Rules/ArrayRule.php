<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Support\Arrayable;
use Stringable;

use function Hypervel\Support\enum_value;

class ArrayRule implements Stringable
{
    /**
     * The accepted keys.
     */
    protected array $keys = [];

    /**
     * Create a new array rule instance.
     */
    public function __construct(mixed $keys = null)
    {
        if ($keys instanceof Arrayable) {
            $keys = $keys->toArray();
        }

        $this->keys = is_array($keys) ? $keys : func_get_args();
    }

    /**
     * Convert the rule to a validation string.
     */
    public function __toString(): string
    {
        if (empty($this->keys)) {
            return 'array';
        }

        $keys = array_map(
            static fn ($key) => enum_value($key),
            $this->keys,
        );

        return 'array:' . implode(',', $keys);
    }
}
