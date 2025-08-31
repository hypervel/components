<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\RequestOptions;
use Hypervel\Socialite\Two\Exceptions\ConfigurationFetchingException;
use Hypervel\Socialite\Two\Exceptions\InvalidAudienceException;
use Hypervel\Socialite\Two\Exceptions\InvalidIssuerException;
use Hypervel\Socialite\Two\Exceptions\InvalidNonceException;
use Hypervel\Socialite\Two\Exceptions\InvalidStateException;
use Hypervel\Socialite\Two\Exceptions\InvalidUserInfoUrlException;
use Hypervel\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Throwable;

abstract class OpenIdProvider extends AbstractProvider
{
    /**
     * Indicates if the nonce should be utilized.
     */
    protected bool $usesNonce = true;

    /**
     * The OpenID Connect configuration.
     */
    protected array $openidConfig = [];

    /**
     * The JSON Web Key Set (JWKS) for the provider.
     * This is used to verify the JWT tokens.
     */
    protected ?array $jwks = null;

    /**
     * Get the base URL for the OIDC provider.
     */
    abstract protected function getBaseUrl(): string;

    /**
     * Redirect the user of the application to the provider's authentication screen.
     */
    public function redirect(): ResponseInterface
    {
        $state = null;
        $nonce = null;

        if ($this->usesState()) {
            $this->request->session()->put('state', $state = $this->getState());
        }

        if ($this->usesPKCE()) {
            $this->request->session()->put('code_verifier', $this->getCodeVerifier());
        }

        if ($this->usesNonce()) {
            $this->request->session()->put('nonce', $nonce = $this->getNonce());
        }

        return $this->response->redirect(
            $this->getAuthUrl($state, $nonce)
        );
    }

    /**
     * Get the authentication URL for the provider.
     */
    protected function getAuthUrl(?string $state, ?string $nonce = null): string
    {
        return $this->buildAuthUrlFromBase(
            $this->getOpenIdConfig()['authorization_endpoint'],
            $state,
            $nonce
        );
    }

    /**
     * Build the authentication URL for the provider from the given base URL.
     */
    protected function buildAuthUrlFromBase(string $url, ?string $state, ?string $nonce = null): string
    {
        return $url . '?' . http_build_query($this->getCodeFields($state, $nonce), '', '&', $this->encodingType);
    }

    /**
     * Get the token URL for the provider.
     */
    protected function getTokenUrl(): string
    {
        return $this->getOpenIdConfig()['token_endpoint'];
    }

    /**
     * Get the user_info URL for the provider.
     */
    protected function getUserInfoUrl(): ?string
    {
        return $this->getOpenIdConfig()['userinfo_endpoint'] ?? null;
    }

    /**
     * Get the jwks URI for the provider.
     */
    protected function getJwksUri(): string
    {
        return $this->getOpenIdConfig()['jwks_uri'];
    }

    /**
     * Get the GET parameters for the code request.
     */
    protected function getCodeFields(?string $state = null, ?string $nonce = null): array
    {
        $fields = parent::getCodeFields($state);

        if ($this->usesNonce()) {
            $fields['nonce'] = $nonce;
        }

        return $fields;
    }

    /**
     * Determine if the provider is operating with nonce.
     */
    protected function usesNonce(): bool
    {
        return $this->usesNonce;
    }

    /**
     * Get the string used for nonce.
     */
    protected function getNonce(): string
    {
        return Str::random(40);
    }

    /**
     * Get the current string used for nonce.
     */
    protected function getCurrentNonce(): ?string
    {
        $nonce = null;

        if ($this->request->session()->has('nonce')) {
            $nonce = $this->request->session()->get('nonce');
        }

        return $nonce;
    }

    /**
     * @throws ConfigurationFetchingException
     */
    protected function getOpenIdConfig(): array
    {
        if ($this->openidConfig) {
            return $this->openidConfig;
        }

        $configUrl = $this->getOpenIdConfigUrl();

        try {
            $response = $this->getHttpClient()->get($configUrl);

            return $this->openidConfig = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new ConfigurationFetchingException('Unable to get the OIDC configuration from ' . $configUrl . ': ' . $e->getMessage());
        }
    }

    /**
     * Get the OpenID Connect configuration URL.
     * This is used to fetch the OIDC configuration.
     */
    protected function getOpenIdConfigUrl(): string
    {
        return rtrim($this->getBaseUrl(), '/') . '/.well-known/openid-configuration';
    }

    /**
     * Get the JSON Web Key Set (JWKS) for the provider.
     */
    protected function getJwks(): array
    {
        if ($this->jwks) {
            return $this->jwks;
        }

        $response = $this->getHttpClient()
            ->get($this->getJwksUri());

        return $this->jwks = JWK::parseKeySet(
            json_decode((string) $response->getBody(), true)
        );
    }

    /**
     * Receive data from auth/callback route
     * code, id_token, scope, state, session_state.
     */
    public function user(): User
    {
        if ($user = $this->getUser()) {
            return $user;
        }

        if ($this->hasInvalidState()) {
            throw new InvalidStateException();
        }

        $user = $this->getUserByTokenResponse(
            $response = $this->getAccessTokenResponse($this->getCode())
        );

        return $this->userInstance($response, $user);
    }

    /**
     * Get user data by the response from the provider.
     */
    protected function getUserByTokenResponse(array $response): ?array
    {
        return $this->getUserByOIDCToken($response['id_token']);
    }

    /**
     * Determine if the current token has a mismatching "nonce".
     * nonce must be validated to prevent replay attacks.
     */
    protected function isInvalidNonce(string $nonce): bool
    {
        if (! $this->usesNonce()) {
            return false;
        }

        return ! (strlen($nonce) > 0 && $nonce === $this->getCurrentNonce());
    }

    /**
     * Get user based on the OIDC token.
     */
    protected function getUserByOIDCToken(string $token): ?array
    {
        $this->validateOIDCPayload(
            $data = (array) JWT::decode($token, $this->getJwks())
        );

        return $data;
    }

    /**
     * Validate the OIDC payload.
     */
    protected function validateOIDCPayload(array $data): void
    {
        if (! isset($data['nonce']) || $this->isInvalidNonce($data['nonce'])) {
            throw new InvalidNonceException();
        }

        if (! isset($data['aud']) || $data['aud'] !== $this->clientId) {
            throw new InvalidAudienceException();
        }

        if (! isset($data['iss']) || $data['iss'] !== $this->getOpenIdConfig()['issuer']) {
            throw new InvalidIssuerException();
        }
    }

    protected function appendOIDCPayload(array $payload): array
    {
        if ($this->usesNonce()) {
            $payload['nonce'] = $this->getCurrentNonce();
        }

        return $payload;
    }

    /**
     * Get the raw user for the given access token.
     */
    protected function getUserByToken(string $token): array
    {
        if (! $userInfoUrl = $this->getUserInfoUrl()) {
            throw new InvalidUserInfoUrlException();
        }

        $response = $this->getHttpClient()->get(
            $userInfoUrl . '?' . http_build_query([
                'access_token' => $token,
            ]),
            [
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json',
                ],
            ]
        );

        return json_decode((string) $response->getBody(), true);
    }
}
