<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Attributes\Validation\Required;
use Hypervel\Data\Data;
use Hypervel\Data\Support\Validation\ValidationContext;

class DummyDataWithContextOverwrittenValidationRules extends Data
{
    public string $string;

    #[Required]
    public bool $validate_as_email;

    /**
     * Require an email when the payload asks for it.
     */
    public static function rules(ValidationContext $context): array
    {
        return $context->payload['validate_as_email'] ?? false
            ? ['string' => ['required', 'string', 'email']]
            : [];
    }
}
