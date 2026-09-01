<?php

declare(strict_types=1);

namespace Hypervel\Data\Concerns;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Data\Support\Validation\ValidationContext;
use Hypervel\Validation\Validator;

/**
 * @method static array rules(?ValidationContext $context = null)
 * @method static array messages(...$args)
 * @method static array attributes(...$args)
 * @method static bool stopOnFirstFailure()
 * @method static string redirect()
 * @method static string redirectRoute()
 * @method static string errorBag()
 */
trait ValidateableData
{
    /**
     * Validate a payload without casting or construction.
     */
    public static function validate(Arrayable|array $payload): Arrayable|array
    {
        return static::factory()->validate($payload);
    }

    /**
     * Validate a payload and create the data object.
     */
    public static function validateAndCreate(Arrayable|array $payload): static
    {
        return static::factory()->alwaysValidate()->from($payload);
    }

    /**
     * Configure the Validator used for the data object.
     */
    public static function withValidator(Validator $validator): void
    {
    }

    /**
     * Get validation rules for a payload.
     */
    public static function getValidationRules(array $payload): array
    {
        return static::factory()->getValidationRules($payload);
    }
}
