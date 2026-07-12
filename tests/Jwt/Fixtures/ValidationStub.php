<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Fixtures;

use Hypervel\Jwt\Validations\AbstractValidation;

class ValidationStub extends AbstractValidation
{
    public function validate(array $payload): void
    {
    }
}
