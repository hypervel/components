<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Auth;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\Auth\BasicAuthenticator;
use Hypervel\Saloon\Http\Auth\DigestAuthenticator;
use Hypervel\Saloon\Http\Auth\NtlmAuthenticator;
use Hypervel\Saloon\Http\Auth\TokenAuthenticator;
use SensitiveParameter;

trait AuthenticatesRequests
{
    /**
     * The authenticator used in requests.
     */
    protected ?Authenticator $authenticator = null;

    /**
     * Resolve the default authenticator.
     */
    protected function defaultAuth(): ?Authenticator
    {
        return null;
    }

    /**
     * Get the authenticator.
     */
    public function authenticator(): ?Authenticator
    {
        return $this->authenticator ?? $this->defaultAuth();
    }

    /**
     * Authenticate the request with an authenticator.
     *
     * @return $this
     */
    public function authenticate(Authenticator $authenticator): static
    {
        $this->authenticator = $authenticator;

        return $this;
    }

    /**
     * Authenticate the request with a token.
     *
     * @return $this
     */
    public function withToken(#[SensitiveParameter] string $token, string $type = 'Bearer'): static
    {
        return $this->authenticate(new TokenAuthenticator($token, $type));
    }

    /**
     * Authenticate the request with basic authentication.
     *
     * @return $this
     */
    public function withBasicAuth(string $username, #[SensitiveParameter] string $password): static
    {
        return $this->authenticate(new BasicAuthenticator($username, $password));
    }

    /**
     * Authenticate the request with digest authentication.
     *
     * @return $this
     */
    public function withDigestAuth(string $username, #[SensitiveParameter] string $password): static
    {
        return $this->authenticate(new DigestAuthenticator($username, $password));
    }

    /**
     * Authenticate the request with NTLM authentication.
     *
     * @return $this
     */
    public function withNtlmAuth(string $username, #[SensitiveParameter] string $password): static
    {
        return $this->authenticate(new NtlmAuthenticator($username, $password));
    }
}
