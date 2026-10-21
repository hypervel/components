<?php

declare(strict_types=1);

namespace Hypervel\Passport\Contracts;

use Hypervel\Contracts\Support\Responsable;

interface ReauthenticationResponse extends Responsable
{
    /**
     * Specify the pending authorization and its confirmation return URL.
     *
     * @param array<string, mixed> $parameters
     */
    public function withParameters(array $parameters = []): static;
}
