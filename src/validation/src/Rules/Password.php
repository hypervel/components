<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules;

use Closure;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Validation\DataAwareRule;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\UncompromisedVerifier;
use Hypervel\Contracts\Validation\Validator as ValidatorContract;
use Hypervel\Contracts\Validation\ValidatorAwareRule;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Validator;
use Hypervel\Conditionable\Traits\Conditionable;
use InvalidArgumentException;

class Password implements Rule, DataAwareRule, ValidatorAwareRule
{
    use Conditionable;

    /**
     * The validator performing the validation.
     */
    protected ?ValidatorContract $validator;

    /**
     * The data under validation.
     */
    protected array $data = [];

    /**
     * The minimum size of the password.
     */
    protected int $min = 8;

    /**
     * The maximum size of the password.
     */
    protected ?int $max = null;

    /**
     * If the password requires at least one uppercase and one lowercase letter.
     */
    protected bool $mixedCase = false;

    /**
     * If the password requires at least one letter.
     */
    protected bool $letters = false;

    /**
     * If the password requires at least one number.
     */
    protected bool $numbers = false;

    /**
     * If the password requires at least one symbol.
     */
    protected bool $symbols = false;

    /**
     * If the password should not have been compromised in data leaks.
     */
    protected bool $uncompromised = false;

    /**
     * The number of times a password can appear in data leaks before being considered compromised.
     */
    protected int $compromisedThreshold = 0;

    /**
     * Additional validation rules that should be merged into the default rules during validation.
     */
    protected array $customRules = [];

    /**
     * The failure messages, if any.
     */
    protected array $messages = [];

    /**
     * The callback that will generate the "default" version of the password rule.
     *
     * @var null|array|callable|string
     */
    public static $defaultCallback;

    /**
     * Create a new rule instance.
     */
    public function __construct(int $min)
    {
        $this->min = max((int) $min, 1);
    }

    /**
     * Set the default callback to be used for determining a password's default rules.
     *
     * If no arguments are passed, the default password rule configuration will be returned.
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
     * Get the default configuration of the password rule.
     */
    public static function default(): mixed
    {
        $password = is_callable(static::$defaultCallback)
            ? call_user_func(static::$defaultCallback)
            : static::$defaultCallback;

        return $password instanceof Rule ? $password : static::min(8);
    }

    /**
     * Get the default configuration of the password rule and mark the field as required.
     */
    public static function required(): array
    {
        return ['required', static::default()];
    }

    /**
     * Get the default configuration of the password rule and mark the field as sometimes being required.
     */
    public static function sometimes(): array
    {
        return ['sometimes', static::default()];
    }

    /**
     * Set the performing validator.
     */
    public function setValidator(ValidatorContract $validator): static
    {
        $this->validator = $validator;

        return $this;
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
     * Set the minimum size of the password.
     */
    public static function min(int $size): static
    {
        return new static($size);
    }

    /**
     * Set the maximum size of the password.
     */
    public function max(int $size): static
    {
        $this->max = $size;

        return $this;
    }

    /**
     * Ensures the password has not been compromised in data leaks.
     */
    public function uncompromised(int $threshold = 0): static
    {
        $this->uncompromised = true;

        $this->compromisedThreshold = $threshold;

        return $this;
    }

    /**
     * Makes the password require at least one uppercase and one lowercase letter.
     */
    public function mixedCase(): static
    {
        $this->mixedCase = true;

        return $this;
    }

    /**
     * Makes the password require at least one letter.
     */
    public function letters(): static
    {
        $this->letters = true;

        return $this;
    }

    /**
     * Makes the password require at least one number.
     */
    public function numbers(): static
    {
        $this->numbers = true;

        return $this;
    }

    /**
     * Makes the password require at least one symbol.
     */
    public function symbols(): static
    {
        $this->symbols = true;

        return $this;
    }

    /**
     * Specify additional validation rules that should be merged with the default rules during validation.
     */
    public function rules(array|Closure|Rule|string $rules): static
    {
        $this->customRules = Arr::wrap($rules);

        return $this;
    }

    /**
     * Determine if the validation rule passes.
     */
    public function passes(string $attribute, mixed $value): bool
    {
        $this->messages = [];

        $validator = Validator::make(
            $this->data,
            [$attribute => [
                'string',
                'min:' . $this->min,
                ...($this->max ? ['max:' . $this->max] : []),
                ...$this->customRules,
            ]],
            $this->validator->customMessages, // @phpstan-ignore-line
            $this->validator->customAttributes // @phpstan-ignore-line
        )->after(function ($validator) use ($attribute, $value) {
            if (! is_string($value)) {
                return;
            }

            if ($this->mixedCase && ! preg_match('/(\p{Ll}+.*\p{Lu})|(\p{Lu}+.*\p{Ll})/u', $value)) {
                $validator->addFailure($attribute, 'password.mixed');
            }

            if ($this->letters && ! preg_match('/\pL/u', $value)) {
                $validator->addFailure($attribute, 'password.letters');
            }

            if ($this->symbols && ! preg_match('/\p{Z}|\p{S}|\p{P}/u', $value)) {
                $validator->addFailure($attribute, 'password.symbols');
            }

            if ($this->numbers && ! preg_match('/\pN/u', $value)) {
                $validator->addFailure($attribute, 'password.numbers');
            }
        });

        if ($validator->fails()) {
            return $this->fail($validator->messages()->all());
        }

        if ($this->uncompromised && ! ApplicationContext::getContainer()->get(UncompromisedVerifier::class)->verify([
            'value' => $value,
            'threshold' => $this->compromisedThreshold,
        ])) {
            $validator->addFailure($attribute, 'password.uncompromised');

            return $this->fail($validator->messages()->all());
        }

        return true;
    }

    /**
     * Get the validation error message.
     */
    public function message(): array
    {
        return $this->messages;
    }

    /**
     * Adds the given failures, and return false.
     */
    protected function fail(array|string $messages): bool
    {
        $this->messages = array_merge($this->messages, Arr::wrap($messages));

        return false;
    }

    /**
     * Get information about the current state of the password validation rules.
     */
    public function appliedRules(): array
    {
        return [
            'min' => $this->min,
            'max' => $this->max,
            'mixedCase' => $this->mixedCase,
            'letters' => $this->letters,
            'numbers' => $this->numbers,
            'symbols' => $this->symbols,
            'uncompromised' => $this->uncompromised,
            'compromisedThreshold' => $this->compromisedThreshold,
            'customRules' => $this->customRules,
        ];
    }
}
