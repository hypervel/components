<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\OAuth2;

use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Body\HasFormBody;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;
use SensitiveParameter;

class GetRefreshTokenRequest extends Request
{
    use AcceptsJson;
    use HasFormBody;

    /**
     * The HTTP method used by the request.
     */
    protected Method $method = Method::POST;

    /**
     * Create a refresh-token request.
     */
    public function __construct(
        protected OAuthConfig $oauthConfig,
        #[SensitiveParameter]
        protected string $refreshToken,
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
     * Resolve the refresh-token request body.
     *
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->oauthConfig->clientId,
            'client_secret' => $this->oauthConfig->clientSecret,
        ];
    }
}
