<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

class PropertyRules
{
    /**
     * Create a property rule set.
     *
     * @param list<ValidationRule> $rules
     */
    public function __construct(
        protected array $rules = []
    ) {
    }

    /**
     * Create a property rule set from the given rules.
     */
    public static function create(ValidationRule ...$rules): self
    {
        return (new self)->add(...$rules);
    }

    /**
     * Append rules, replacing existing rules of the same type.
     *
     * @return $this
     */
    public function add(ValidationRule ...$rules): static
    {
        $this->removeType(...$rules);

        array_push($this->rules, ...$rules);

        return $this;
    }

    /**
     * Prepend rules, replacing existing rules of the same type.
     *
     * @return $this
     */
    public function prepend(ValidationRule ...$rules): static
    {
        $this->removeType(...$rules);

        array_unshift($this->rules, ...$rules);

        return $this;
    }

    /**
     * Remove rules of the given types. Any requiring rule removes every requiring rule.
     *
     * @param class-string<ValidationRule>|ValidationRule ...$classes
     * @return $this
     */
    public function removeType(string|ValidationRule ...$classes): static
    {
        foreach ($this->rules as $i => $rule) {
            foreach ($classes as $class) {
                if ($class instanceof RequiringRule && $rule instanceof RequiringRule) {
                    unset($this->rules[$i]);

                    continue 2;
                }

                if ($rule instanceof $class) {
                    unset($this->rules[$i]);

                    continue 2;
                }
            }
        }

        $this->rules = array_values($this->rules);

        return $this;
    }

    /**
     * Determine if a rule of the given type is present.
     *
     * @param class-string $class
     */
    public function hasType(string $class): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get every rule in order.
     *
     * @return list<ValidationRule>
     */
    public function all(): array
    {
        return $this->rules;
    }
}
