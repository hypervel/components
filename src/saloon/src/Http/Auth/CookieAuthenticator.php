<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\PendingRequest;
use InvalidArgumentException;
use SensitiveParameter;

readonly class CookieAuthenticator implements Authenticator
{
    /**
     * Create a cookie authenticator.
     */
    public function __construct(
        public string $name,
        #[SensitiveParameter]
        public string $value,
        public ?string $domain = null,
    ) {
        if ($domain === '') {
            throw new InvalidArgumentException('The cookie domain cannot be empty.');
        }
    }

    /**
     * Apply the authentication to the request.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $pendingRequest->withCookies(
            [$this->name => $this->value],
            $this->domain ?? $pendingRequest->uri()->getHost(),
        );
    }
}
