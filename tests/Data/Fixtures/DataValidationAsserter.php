<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Contracts\ValidateableData;
use Hypervel\Database\Query\Builder;
use Hypervel\Support\Facades\DB;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;
use Hypervel\Validation\ValidationException;
use Hypervel\Validation\ValidationRuleParser;
use Hypervel\Validation\Validator;
use PHPUnit\Framework\Assert;

/**
 * Fluent validation assertions for one data class.
 */
class DataValidationAsserter
{
    /**
     * Create an asserter for a data class.
     *
     * @param class-string<ValidateableData> $dataClass
     */
    public function __construct(
        protected readonly string $dataClass,
    ) {
    }

    /**
     * Create an asserter for a data class or object.
     *
     * @param class-string<ValidateableData>|ValidateableData $dataClass
     */
    public static function for(string|ValidateableData $dataClass): self
    {
        return new self(is_object($dataClass) ? $dataClass::class : $dataClass);
    }

    /**
     * Assert the payload passes validation.
     */
    public function assertOk(array $payload): self
    {
        $this->dataClass::validate($payload);

        Assert::assertTrue(true);

        return $this;
    }

    /**
     * Assert the payload fails validation, optionally with exactly the given errors.
     */
    public function assertErrors(array $payload, ?array $errors = null): self
    {
        $exception = $this->validationException($payload);

        if ($errors === null) {
            Assert::assertNotEmpty($exception->errors());
        } else {
            Assert::assertSame($errors, $exception->errors());
        }

        return $this;
    }

    /**
     * Assert the rules compiled for the payload, expanded against the payload like the validator does.
     */
    public function assertRules(array $rules, array $payload = []): self
    {
        $parser = new ValidationRuleParser($payload);

        Assert::assertEquals(
            $this->normalizeDatabaseRules($rules),
            $this->normalizeDatabaseRules($parser->explode($this->dataClass::getValidationRules($payload))->rules),
        );

        return $this;
    }

    /**
     * Assert the payload fails validation with the given redirect.
     */
    public function assertRedirect(array $payload, string $redirect): self
    {
        Assert::assertSame($redirect, $this->validationException($payload)->redirectTo);

        return $this;
    }

    /**
     * Assert the payload fails validation with the given error bag.
     */
    public function assertErrorBag(array $payload, string $errorBag): self
    {
        Assert::assertSame($errorBag, $this->validationException($payload)->errorBag);

        return $this;
    }

    /**
     * Assert the custom messages given to the validator for the payload.
     */
    public function assertMessages(array $messages, array $payload = []): self
    {
        Assert::assertEquals($messages, $this->validator($payload)->customMessages);

        return $this;
    }

    /**
     * Assert the custom attribute names given to the validator for the payload.
     */
    public function assertAttributes(array $attributes, array $payload = []): self
    {
        Assert::assertEquals($attributes, $this->validator($payload)->customAttributes);

        return $this;
    }

    /**
     * Validate the payload and return the exception it must fail with.
     */
    protected function validationException(array $payload): ValidationException
    {
        try {
            $this->dataClass::validate($payload);
        } catch (ValidationException $exception) {
            return $exception;
        }

        Assert::fail('No validation errors');
    }

    /**
     * Get the validator configured for the payload.
     */
    protected function validator(array $payload): Validator
    {
        $validator = null;

        try {
            $this->dataClass::factory()
                ->withValidator(static function (Validator $instance) use (&$validator): void {
                    $validator = $instance;
                })
                ->validate($payload);
        } catch (ValidationException) {
            // Only the configured validator is needed; the payload may fail.
        }

        return $validator;
    }

    /**
     * Replace database rules that have query callbacks with the query those callbacks build.
     *
     * Callbacks are closures that cannot be compared, so the query they build shows what they do.
     */
    protected function normalizeDatabaseRules(array $rules): array
    {
        return array_map(
            static fn (array $fieldRules): array => array_map(
                static function (mixed $rule): mixed {
                    if (! ($rule instanceof Exists || $rule instanceof Unique) || $rule->queryCallbacks() === []) {
                        return $rule;
                    }

                    $query = DB::query();

                    // As DatabasePresenceVerifier does, each callback receives its own nested where group.
                    foreach ($rule->queryCallbacks() as $callback) {
                        $query->where(static function (Builder $query) use ($callback): void {
                            $callback($query);
                        });
                    }

                    return [(string) $rule, $query->toSql(), $query->getBindings()];
                },
                $fieldRules,
            ),
            $rules,
        );
    }
}
