<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\PendingRequest;
use SensitiveParameter;

readonly class CertificateAuthenticator implements Authenticator
{
    /**
     * Create a certificate authenticator.
     */
    public function __construct(
        public string $path,
        #[SensitiveParameter]
        public ?string $password = null,
    ) {
    }

    /**
     * Apply the authentication to the request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $pendingRequest->setCertificate($this->path, $this->password);
    }
}
