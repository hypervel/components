<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Hypervel\Contracts\Validation\DataAwareRule;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\Validator as ValidatorContract;
use Hypervel\Contracts\Validation\ValidatorAwareRule;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Validator;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;

class Email implements Rule, DataAwareRule, ValidatorAwareRule
{
    use Conditionable;
    use Macroable;

    public bool $validateMxRecord = false;

    public bool $preventSpoofing = false;

    public bool $nativeValidation = false;

    public bool $nativeValidationWithUnicodeAllowed = false;

    public bool $rfcCompliant = false;

    public bool $strictRfcCompliant = false;

    /**
     * The validator performing the validation.
     */
    protected ?ValidatorContract $validator = null;

    /**
     * The data under validation.
     */
    protected array $data = [];

    /**
     * An array of custom rules that will be merged into the validation rules.
     */
    protected array $customRules = [];

    /**
     * The error message after validation, if any.
     */
    protected array $messages = [];

    /**
     * The callback that will generate the "default" version of the file rule.
     *
     * @var null|array|callable|string
     */
    public static $defaultCallback;

    /**
     * Set the default callback to be used for determining the email default rules.
     *
     * If no arguments are passed, the default email rule configuration will be returned.
     *
     * @param null|callable|static $callback
     */
    public static function defaults(mixed $callback = null): ?static
    {
        if (is_null($callback)) {
            return static::default();
        }

        if (! is_callable($callback) && ! $callback instanceof static) { // @phpstan-ignore instanceof.alwaysTrue, booleanAnd.alwaysFalse (callable values like closures are not instances)
            throw new InvalidArgumentException('The given callback should be callable or an instance of ' . static::class);
        }

        static::$defaultCallback = $callback;

        return null;
    }

    /**
     * Get the default configuration of the file rule.
     */
    public static function default(): static
    {
        $email = is_callable(static::$defaultCallback)
            ? call_user_func(static::$defaultCallback)
            : static::$defaultCallback;

        return $email instanceof static ? $email : new static();
    }

    /**
     * Ensure that the email is an RFC compliant email address.
     */
    public function rfcCompliant(bool $strict = false): static
    {
        if ($strict) {
            $this->strictRfcCompliant = true;
        } else {
            $this->rfcCompliant = true;
        }

        return $this;
    }

    /**
     * Ensure that the email is a strictly enforced RFC compliant email address.
     */
    public function strict(): static
    {
        return $this->rfcCompliant(true);
    }

    /**
     * Ensure that the email address has a valid MX record.
     *
     * Requires the PHP intl extension.
     */
    public function validateMxRecord(): static
    {
        $this->validateMxRecord = true;

        return $this;
    }

    /**
     * Ensure that the email address is not attempting to spoof another email address using invalid unicode characters.
     */
    public function preventSpoofing(): static
    {
        $this->preventSpoofing = true;

        return $this;
    }

    /**
     * Ensure the email address is valid using PHP's native email validation functions.
     */
    public function withNativeValidation(bool $allowUnicode = false): static
    {
        if ($allowUnicode) {
            $this->nativeValidationWithUnicodeAllowed = true;
        } else {
            $this->nativeValidation = true;
        }

        return $this;
    }

    /**
     * Specify additional validation rules that should be merged with the default rules during validation.
     */
    public function rules(array|string $rules): static
    {
        $this->customRules = array_merge($this->customRules, Arr::wrap($rules));

        return $this;
    }

    /**
     * Determine if the validation rule passes.
     */
    public function passes(string $attribute, mixed $value): bool
    {
        $this->messages = [];

        if (! is_string($value) && ! (is_object($value) && method_exists($value, '__toString'))) {
            return false;
        }

        $validator = Validator::make(
            $this->data,
            [$attribute => $this->buildValidationRules()],
            $this->validator->customMessages, // @phpstan-ignore-line
            $this->validator->customAttributes // @phpstan-ignore-line
        );

        if ($validator->fails()) {
            $this->messages = array_merge($this->messages, $validator->messages()->all());

            return false;
        }

        return true;
    }

    /**
     * Build the array of underlying validation rules based on the current state.
     */
    protected function buildValidationRules(): array
    {
        $rules = [];

        if ($this->rfcCompliant) {
            $rules[] = 'rfc';
        }

        if ($this->strictRfcCompliant) {
            $rules[] = 'strict';
        }

        if ($this->validateMxRecord) {
            $rules[] = 'dns';
        }

        if ($this->preventSpoofing) {
            $rules[] = 'spoof';
        }

        if ($this->nativeValidation) {
            $rules[] = 'filter';
        }

        if ($this->nativeValidationWithUnicodeAllowed) {
            $rules[] = 'filter_unicode';
        }

        if ($rules) {
            $rules = ['email:' . implode(',', $rules)];
        } else {
            $rules = ['email'];
        }

        return array_merge(array_filter($rules), $this->customRules); // @phpstan-ignore arrayFilter.same (defensive)
    }

    /**
     * Get the validation error message.
     */
    public function message(): array
    {
        return $this->messages;
    }

    /**
     * Set the current validator.
     */
    public function setValidator(ValidatorContract $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * Set the current data under validation.
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }
}
