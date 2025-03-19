<?php

declare(strict_types=1);

namespace Hypervel\JWT\Validations;

use Carbon\Carbon;
use Hypervel\JWT\Exceptions\TokenExpiredException;

class ExpiredClaim extends AbstractValidation
{
    public function validate(array $payload): void
    {
        if (! $exp = ($payload['exp'] ?? null)) {
            return;
        }

        if (Carbon::now() > $this->timestamp($exp)->addSeconds($this->config['leeway'] ?? 0)) {
            throw new TokenExpiredException('Token has expired');
        }
    }
}
