<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Validations;

use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Support\Facades\Date;

class NotBeforeClaim extends AbstractValidation
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
