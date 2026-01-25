<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\Validator;
use Hypervel\Contracts\Validation\ValidatorAwareRule;
use TypeError;
use UnitEnum;

class Enum implements Rule, ValidatorAwareRule
{
    use Conditionable;

    /**
     * The current validator instance.
     */
    protected ?Validator $validator = null;

    /**
     * The cases that should be considered valid.
     */
    protected array $only = [];

    /**
     * The cases that should be considered invalid.
     */
    protected array $except = [];

    /**
     * Create a new rule instance.
     *
     * @param class-string<UnitEnum> $type the type of the enum
     */
    public function __construct(
        protected string $type
    ) {
    }

    /**
     * Determine if the validation rule passes.
     */
    public function passes(string $attribute, mixed $value): bool
    {
        if ($value instanceof $this->type) {
            return $this->isDesirable($value);
        }

        if (is_null($value) || ! enum_exists($this->type) || ! method_exists($this->type, 'tryFrom')) {
            return false;
        }

        try {
            /* @phpstan-ignore-next-line */
            $value = $this->type::tryFrom($value);

            return ! is_null($value) && $this->isDesirable($value);
        } catch (TypeError) {
            return false;
        }
    }

    /**
     * Specify the cases that should be considered valid.
     *
     * @param Arrayable<array-key, UnitEnum>|UnitEnum|UnitEnum[] $values
     */
    public function only(mixed $values): static
    {
        $this->only = $values instanceof Arrayable ? $values->toArray() : Arr::wrap($values);

        return $this;
    }

    /**
     * Specify the cases that should be considered invalid.
     *
     * @param Arrayable<array-key, UnitEnum>|UnitEnum|UnitEnum[] $values
     */
    public function except(mixed $values)
    {
        $this->except = $values instanceof Arrayable ? $values->toArray() : Arr::wrap($values);

        return $this;
    }

    /**
     * Determine if the given case is a valid case based on the only / except values.
     */
    protected function isDesirable(mixed $value): bool
    {
        return match (true) {
            ! empty($this->only) => in_array(needle: $value, haystack: $this->only, strict: true),
            ! empty($this->except) => ! in_array(needle: $value, haystack: $this->except, strict: true),
            default => true,
        };
    }

    /**
     * Get the validation error message.
     */
    public function message(): array|string
    {
        $message = $this->validator->getTranslator()->get('validation.enum');

        return $message === 'validation.enum'
            ? ['The selected :attribute is invalid.']
            : $message;
    }

    /**
     * Set the current validator.
     */
    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }
}
