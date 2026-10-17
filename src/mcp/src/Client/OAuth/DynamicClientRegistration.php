<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\OAuth;

use Hypervel\Mcp\Client\Exceptions\OAuthException;
use Hypervel\Mcp\Client\OAuth\Concerns\InteractsWithOAuthEndpoints;
use Hypervel\Mcp\Client\OAuth\Enums\TokenEndpointAuthMethod;

class DynamicClientRegistration
{
    use InteractsWithOAuthEndpoints;

    /**
     * Register a client with the authorization server.
     */
    public function register(
        string $registrationEndpoint,
        string $redirectUri,
        ?string $scope = null,
        string $clientName = 'Hypervel MCP Client',
        string $applicationType = 'web',
        TokenEndpointAuthMethod $tokenEndpointAuthMethod = TokenEndpointAuthMethod::ClientSecretPost,
    ): ClientRegistration {
        $request = $this->oAuthRequest()->asJson();
        $destination = $this->resolveOAuthDestination($request, $registrationEndpoint);
        $response = $request->post((string) $destination->uri, array_filter([
            'client_name' => $clientName,
            'redirect_uris' => [$redirectUri],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $tokenEndpointAuthMethod->value,
            'application_type' => $applicationType,
            'scope' => $scope,
        ], static fn (mixed $value): bool => $value !== null));

        if (! $response->successful()) {
            throw new OAuthException("Dynamic client registration failed with status [{$response->status()}].");
        }

        $data = $response->json();

        if (! is_array($data) || empty($data['client_id'])) {
            throw new OAuthException('Dynamic client registration response did not include a client_id.');
        }

        return new ClientRegistration(
            clientId: (string) $data['client_id'],
            clientSecret: isset($data['client_secret']) ? (string) $data['client_secret'] : null,
        );
    }
}
