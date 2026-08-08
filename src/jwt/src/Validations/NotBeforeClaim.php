<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Validations;

use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Support\Facades\Date;

/**
 * Validate not-before timestamps during normal decoding and refresh.
 *
 * This validation deliberately does not implement TemporalValidation. Skipping
 * it during refresh would replace a future `nbf` with the current time.
 */
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
