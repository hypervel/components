<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Translation\CreatesPotentiallyTranslatedStrings;
use Hypervel\Validation\Contracts\DataAwareRule;
use Hypervel\Validation\Contracts\ImplicitRule;
use Hypervel\Validation\Contracts\InvokableRule;
use Hypervel\Validation\Contracts\Rule;
use Hypervel\Validation\Contracts\ValidationRule;
use Hypervel\Validation\Contracts\Validator;
use Hypervel\Validation\Contracts\ValidatorAwareRule;

class InvokableValidationRule implements Rule, ValidatorAwareRule
{
    use CreatesPotentiallyTranslatedStrings;

    /**
     * Indicates if the validation invokable failed.
     */
    protected bool $failed = false;

    /**
     * The validation error messages.
     */
    protected array $messages = [];

    /**
     * The current validator.
     */
    protected ?Validator $validator;

    /**
     * The data under validation.
     */
    protected array $data = [];

    /**
     * Create a new explicit Invokable validation rule.
     *
     * @param InvokableRule|ValidationRule $invokable the invokable that validates the attribute
     */
    protected function __construct(
        protected InvokableRule|ValidationRule $invokable
    ) {
    }

    /**
     * Create a new implicit or explicit Invokable validation rule.
     */
    public static function make(InvokableRule|ValidationRule $invokable): InvokableValidationRule
    {
        if ($invokable->implicit ?? false) {
            return new class($invokable) extends InvokableValidationRule implements ImplicitRule {};
        }

        return new InvokableValidationRule($invokable);
    }

    /**
     * Determine if the validation rule passes.
     */
    public function passes(string $attribute, mixed $value): bool
    {
        $this->failed = false;

        if ($this->invokable instanceof DataAwareRule) {
            $this->invokable->setData($this->validator->getData());
        }

        if ($this->invokable instanceof ValidatorAwareRule) {
            $this->invokable->setValidator($this->validator);
        }

        $method = $this->invokable instanceof ValidationRule
            ? 'validate'
            : '__invoke';

        $this->invokable->{$method}($attribute, $value, function ($attribute, $message = null) {
            $this->failed = true;

            return $this->pendingPotentiallyTranslatedString($attribute, $message);
        });

        return ! $this->failed;
    }

    /**
     * Get the underlying invokable rule.
     */
    public function invokable(): InvokableRule|ValidationRule
    {
        return $this->invokable;
    }

    /**
     * Get the validation error messages.
     */
    public function message(): array
    {
        return $this->messages;
    }

    /**
     * Set the data under validation.
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
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
