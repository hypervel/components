<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Arr;
use Stringable;

use function Hypervel\Support\enum_value;

class ArrayRule implements Stringable
{
    /**
     * The accepted keys.
     */
    protected array $keys = [];

    /**
     * The additional constraints for the array rule.
     */
    protected array $constraints = [];

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
     * The field under validation must have between the given min and max number of items (inclusive).
     *
     * @param non-negative-int $min
     * @param non-negative-int $max
     */
    public function between(int $min, int $max): static
    {
        return $this->addRule('between:' . $min . ',' . $max);
    }

    /**
     * The field under validation must have exactly the given number of items.
     */
    public function exactly(int $value): static
    {
        return $this->addRule('size:' . $value);
    }

    /**
     * The field under validation must not have more than the given number of items.
     *
     * @param non-negative-int $value
     */
    public function max(int $value): static
    {
        return $this->addRule('max:' . $value);
    }

    /**
     * The field under validation must have at least the given number of items.
     */
    public function min(int $value): static
    {
        return $this->addRule('min:' . $value);
    }

    /**
     * Convert the rule to a validation string.
     */
    public function __toString(): string
    {
        return implode('|', $this->toArray());
    }

    /**
     * Convert the rule to an array of validation rules.
     *
     * @return list<string>
     */
    public function toArray(): array
    {
        return array_values(array_unique([$this->arrayRule(), ...$this->constraints]));
    }

    /**
     * Get the array rule with its accepted keys.
     */
    protected function arrayRule(): string
    {
        if (empty($this->keys)) {
            return 'array';
        }

        $keys = array_map(
            static fn ($key): string => '"' . str_replace('"', '""', (string) enum_value($key)) . '"',
            $this->keys,
        );

        return 'array:' . implode(',', $keys);
    }

    /**
     * Add custom rules to the validation rules array.
     */
    protected function addRule(array|string $rules): static
    {
        $this->constraints = array_merge($this->constraints, Arr::wrap($rules));

        return $this;
    }
}
