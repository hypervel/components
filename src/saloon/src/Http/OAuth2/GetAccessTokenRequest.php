<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\OAuth2;

use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Body\HasFormBody;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;
use SensitiveParameter;

class GetAccessTokenRequest extends Request
{
    use AcceptsJson;
    use HasFormBody;

    /**
     * The HTTP method used by the request.
     */
    protected Method $method = Method::POST;

    /**
     * Create an authorization-code token request.
     */
    public function __construct(
        #[SensitiveParameter]
        protected string $code,
        protected OAuthConfig $oauthConfig,
        #[SensitiveParameter]
        protected ?string $codeVerifier = null,
    ) {
    }

    /**
     * Resolve the token endpoint.
     */
    public function resolveEndpoint(): string
    {
        return $this->oauthConfig->tokenEndpoint;
    }

    /**
     * Resolve whether the trusted OAuth endpoint may replace the connector base URL.
     */
    public function allowsBaseUrlOverride(): bool
    {
        return $this->oauthConfig->allowBaseUrlOverride;
    }

    /**
     * Resolve the authorization-code request body.
     *
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'grant_type' => 'authorization_code',
            'code' => $this->code,
            'client_id' => $this->oauthConfig->clientId,
            'client_secret' => $this->oauthConfig->clientSecret,
            'redirect_uri' => $this->oauthConfig->redirectUri,
            'code_verifier' => $this->codeVerifier,
        ], static fn (?string $value): bool => $value !== null);
    }
}
