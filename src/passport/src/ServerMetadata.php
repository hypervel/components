<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Routing\Router;
use Hypervel\Support\Uri;
use LogicException;

class ServerMetadata implements Arrayable
{
    /**
     * Create the authorization server metadata builder.
     */
    public function __construct(
        protected ServerFactory $servers,
        protected UrlGenerator $urls,
        protected Router $router,
    ) {
    }

    /**
     * Get the RFC 8414 discovery location for the current issuer.
     */
    public function discoveryUrl(): ?string
    {
        if (($issuer = $this->servers->settings()->issuer) === null) {
            return null;
        }

        $uri = Uri::of($issuer)->getUri();

        // Normalize the HTTP location, never the issuer value compared by OAuth clients.
        return $uri->withPath('/.well-known/oauth-authorization-server' . rtrim($uri->getPath(), '/'))->toString();
    }

    /**
     * Describe the current server's enabled OAuth capabilities.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $metadata = [
            'issuer' => $this->servers->settings()->issuer
                ?? throw new LogicException('Configure a Passport issuer before publishing authorization server metadata.'),
            'authorization_endpoint' => $this->urls->route('passport.authorizations.authorize'),
            'token_endpoint' => $this->urls->route('passport.token'),
            'jwks_uri' => $this->urls->route('passport.jwks'),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query', 'form_post'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'token_endpoint_auth_methods_supported' => ClientAuthenticator::METHODS,
            'token_endpoint_auth_signing_alg_values_supported' => JwkValidator::ALGORITHMS,
            'code_challenge_methods_supported' => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
        ];

        if ($scopes = Passport::scopeIds()) {
            $metadata['scopes_supported'] = $scopes;
        }

        if (Passport::$deviceCodeGrantEnabled && $this->router->has('passport.device')) {
            $metadata['device_authorization_endpoint'] = $this->urls->route('passport.device.code');
            $metadata['grant_types_supported'][] = 'urn:ietf:params:oauth:grant-type:device_code';
        }

        if ($this->router->has('passport.clients.register')) {
            $metadata['registration_endpoint'] = $this->urls->route('passport.clients.register');
        }

        if ($this->router->has('passport.par')) {
            $metadata['pushed_authorization_request_endpoint'] = $this->urls->route('passport.par');
            $metadata['require_pushed_authorization_requests'] = false;
        }

        if ($this->router->has('passport.token.revoke')) {
            $metadata['revocation_endpoint'] = $this->urls->route('passport.token.revoke');
            $metadata['revocation_endpoint_auth_methods_supported'] = ClientAuthenticator::METHODS;
            $metadata['revocation_endpoint_auth_signing_alg_values_supported'] = JwkValidator::ALGORITHMS;
        }

        if ($this->router->has('passport.token.introspect')) {
            $metadata['introspection_endpoint'] = $this->urls->route('passport.token.introspect');
            $metadata['introspection_endpoint_auth_methods_supported'] = array_values(array_diff(ClientAuthenticator::METHODS, ['none']));
            $metadata['introspection_endpoint_auth_signing_alg_values_supported'] = JwkValidator::ALGORITHMS;
        }

        return $metadata;
    }
}
