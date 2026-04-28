<?php

declare(strict_types=1);

namespace Hypervel\JWT\Validations;

use Hypervel\JWT\Exceptions\TokenInvalidException;
use Hypervel\Support\Facades\Date;

class IssuedAtClaim extends AbstractValidation
{
    public function validate(array $payload): void
    {
        if (! $iat = ($payload['iat'] ?? null)) {
            return;
        }

        if ($this->timestamp($iat)->subSeconds($this->config['leeway'] ?? 0) > Date::now()) {
            throw new TokenInvalidException('Issued At (iat) timestamp cannot be in the future');
        }
    }
}
