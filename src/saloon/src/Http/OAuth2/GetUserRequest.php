<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\OAuth2;

use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;

class GetUserRequest extends Request
{
    use AcceptsJson;

    /**
     * The HTTP method used by the request.
     */
    protected Method $method = Method::GET;

    /**
     * Create an OAuth user request.
     */
    public function __construct(protected OAuthConfig $oauthConfig)
    {
    }

    /**
     * Resolve the user endpoint.
     */
    public function resolveEndpoint(): string
    {
        return $this->oauthConfig->userEndpoint;
    }

    /**
     * Resolve whether the trusted OAuth endpoint may replace the connector base URL.
     */
    public function allowsBaseUrlOverride(): bool
    {
        return $this->oauthConfig->allowBaseUrlOverride;
    }
}
