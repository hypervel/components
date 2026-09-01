<?php

declare(strict_types=1);

namespace Hypervel\Data\Contracts;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Validation\Validator;

interface ValidateableData
{
    /**
     * Validate the given payload.
     */
    public static function validate(Arrayable|array $payload): Arrayable|array;

    /**
     * Validate the payload and create the data object.
     */
    public static function validateAndCreate(Arrayable|array $payload): static;

    /**
     * Get validation rules for the given payload.
     */
    public static function getValidationRules(array $payload): array;

    /**
     * Configure the validator used for the data object.
     */
    public static function withValidator(Validator $validator): void;
}
