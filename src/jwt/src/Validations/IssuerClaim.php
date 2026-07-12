<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Validations;

use Hypervel\Jwt\Exceptions\TokenInvalidException;

class IssuerClaim extends AbstractValidation
{
    /**
     * Validate the issuer claim.
     */
    public function validate(array $payload): void
    {
        $issuer = $this->config['issuer'] ?? null;

        if ($issuer === null || $issuer === '') {
            return;
        }

        if (($payload['iss'] ?? null) !== $issuer) {
            throw new TokenInvalidException('Issuer is invalid');
        }
    }
}
