<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\OAuth2;

use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Connector;

/**
 * @phpstan-require-extends Connector
 * @phpstan-ignore trait.unused (user-facing OAuth 2 trait)
 */
trait HasOAuthConfig
{
    /**
     * The immutable OAuth 2 configuration.
     */
    protected ?OAuthConfig $oauthConfig = null;

    /**
     * Get the OAuth 2 configuration.
     */
    public function oauthConfig(): OAuthConfig
    {
        return $this->oauthConfig ??= $this->defaultOAuthConfig();
    }

    /**
     * Define the default OAuth 2 configuration.
     */
    abstract protected function defaultOAuthConfig(): OAuthConfig;
}
