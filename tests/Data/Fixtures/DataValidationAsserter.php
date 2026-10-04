<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Contracts\ValidateableData;
use Hypervel\Validation\ValidationException;
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
        try {
            $this->dataClass::validate($payload);
        } catch (ValidationException $exception) {
            if ($errors === null) {
                Assert::assertNotEmpty($exception->errors());
            } else {
                Assert::assertSame($errors, $exception->errors());
            }

            return $this;
        }

        Assert::fail('No validation errors');
    }

    /**
     * Assert the rules compiled for the payload.
     */
    public function assertRules(array $rules, array $payload = []): self
    {
        Assert::assertEquals($rules, $this->dataClass::getValidationRules($payload));

        return $this;
    }
}
