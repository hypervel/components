<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Support\Facades\Gate;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\Validator;
use Hypervel\Contracts\Validation\ValidatorAwareRule;

class Can implements Rule, ValidatorAwareRule
{
    /**
     * The current validator instance.
     */
    protected ?Validator $validator = null;

    /**
     * Constructor.
     *
     * @param string $ability the ability to check
     * @param array $arguments the arguments to pass to the authorization check
     */
    public function __construct(
        protected string $ability,
        protected array $arguments = []
    ) {
        $this->ability = $ability;
        $this->arguments = $arguments;
    }

    /**
     * Determine if the validation rule passes.
     */
    public function passes(string $attribute, mixed $value): bool
    {
        $arguments = $this->arguments;

        $model = array_shift($arguments);

        return Gate::allows($this->ability, array_filter([$model, ...$arguments, $value]));
    }

    /**
     * Get the validation error message.
     */
    public function message(): array|string
    {
        $message = $this->validator->getTranslator()->get('validation.can');

        return $message === 'validation.can'
            ? ['The :attribute field contains an unauthorized value.']
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
