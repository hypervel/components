<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT\Stub;

use Hypervel\JWT\Validations\AbstractValidation;

class ValidationStub extends AbstractValidation
{
    public function validate(array $payload): void
    {
    }
}
