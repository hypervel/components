<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\OAuth2;

use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Body\HasFormBody;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;

class GetClientCredentialsTokenRequest extends Request
{
    use AcceptsJson;
    use HasFormBody;

    /**
     * The HTTP method used by the request.
     */
    protected Method $method = Method::POST;

    /**
     * Create a client-credentials token request.
     *
     * @param list<string> $scopes
     */
    public function __construct(
        protected OAuthConfig $oauthConfig,
        protected array $scopes = [],
        protected string $scopeSeparator = ' ',
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
     * Resolve the client-credentials request body.
     *
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return [
            'grant_type' => 'client_credentials',
            'client_id' => $this->oauthConfig->clientId,
            'client_secret' => $this->oauthConfig->clientSecret,
            'scope' => implode($this->scopeSeparator, [
                ...$this->oauthConfig->defaultScopes,
                ...$this->scopes,
            ]),
        ];
    }
}
