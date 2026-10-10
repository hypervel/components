<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Attributes\Validation\Email;
use Hypervel\Data\Data;

class SimpleDataWithExplicitValidationRuleAttributeData extends Data
{
    /**
     * Create the email data fixture.
     */
    public function __construct(
        #[Email]
        public string $email,
    ) {
    }
}
