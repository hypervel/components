<?php

declare(strict_types=1);

namespace Hypervel\JWT\Validations;

use Hypervel\JWT\Exceptions\TokenInvalidException;
use Hypervel\Support\Facades\Date;

class NotBeforeCliam extends AbstractValidation
{
    public function validate(array $payload): void
    {
        if (! $nbf = ($payload['nbf'] ?? null)) {
            return;
        }

        if ($this->timestamp($nbf)->subSeconds($this->config['leeway'] ?? 0) > Date::now()) {
            throw new TokenInvalidException('Not Before (nbf) timestamp cannot be in the future');
        }
    }
}
