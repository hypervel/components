<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use GuzzleHttp\Cookie\SetCookie;
use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\PendingRequest;
use SensitiveParameter;

class CookieAuthenticator implements Authenticator
{
    /**
     * Create a cookie authenticator.
     */
    public function __construct(
        public readonly string $name,
        #[SensitiveParameter]
        public readonly string $value,
        public readonly ?string $domain = null,
    ) {
    }

    /**
     * Apply the authentication to the request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $uri = $pendingRequest->uri();

        $pendingRequest->withCookie(new SetCookie([
            'Name' => $this->name,
            'Value' => $this->value,
            'Domain' => $this->domain ?? $uri->getHost(),
            'HostOnly' => $this->domain === null,
            'Secure' => $uri->getScheme() === 'https',
            'Discard' => true,
        ]));
    }
}
