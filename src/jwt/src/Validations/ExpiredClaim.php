<?php

declare(strict_types=1);

namespace Hypervel\JWT\Validations;

use Hypervel\JWT\Exceptions\TokenExpiredException;
use Hypervel\Support\Facades\Date;

class ExpiredClaim extends AbstractValidation
{
    public function validate(array $payload): void
    {
        if (! $exp = ($payload['exp'] ?? null)) {
            return;
        }

        if (Date::now() > $this->timestamp($exp)->addSeconds($this->config['leeway'] ?? 0)) {
            throw new TokenExpiredException('Token has expired');
        }
    }
}
