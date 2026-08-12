<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use GuzzleHttp\RequestOptions;
use Hypervel\Http\RedirectResponse;
use Hypervel\Socialite\Two\Concerns\InteractsWithJwks;
use Hypervel\Socialite\Two\Exceptions\ConfigurationFetchingException;
use Hypervel\Socialite\Two\Exceptions\InvalidIssuerException;
use Hypervel\Socialite\Two\Exceptions\InvalidNonceException;
use Hypervel\Socialite\Two\Exceptions\InvalidUserInfoUrlException;
use Hypervel\Support\Str;
use SensitiveParameter;
use Throwable;
use UnexpectedValueException;

abstract class OpenIdProvider extends AbstractProvider
{
    use InteractsWithJwks;

    /**
     * Indicates if the nonce should be utilized.
     */
    protected bool $usesNonce = true;

    /**
     * The OpenID Connect configuration.
     *
     * @var null|array{url: string, config: array}
     */
    protected ?array $openidConfig = null;

    /**
     * Get the base URL for the OIDC provider.
     */
    abstract protected function getBaseUrl(): string;

    /**
     * Redirect the user of the application to the provider's authentication screen.
     */
    public function redirect(): RedirectResponse
    {
        $state = null;
        $nonce = null;

        if ($this->usesState()) {
            $this->getRequest()->session()->put('state', $state = $this->getState());
        }

        if ($this->usesPKCE()) {
            $this->getRequest()->session()->put('code_verifier', $this->getCodeVerifier());
        }

        if ($this->usesNonce()) {
            $this->getRequest()->session()->put('nonce', $nonce = $this->getNonce());
        }

        return new RedirectResponse($this->getAuthUrl($state, $nonce));
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
    protected function getJwksUri(bool $refresh = false): string
    {
        return $this->getOpenIdConfig($refresh)['jwks_uri'];
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
        return $this->getRequest()->session()->pull('nonce');
    }

    /**
     * @throws ConfigurationFetchingException
     */
    protected function getOpenIdConfig(bool $refresh = false): array
    {
        $url = $this->getOpenIdConfigUrl();

        if (! $refresh && ($this->openidConfig['url'] ?? null) === $url) {
            return $this->openidConfig['config'];
        }

        try {
            $response = $this->getHttpClient()->get($url);
            $config = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($config) || array_is_list($config)) {
                throw new UnexpectedValueException('The OIDC configuration response must be a JSON object with named fields.');
            }
        } catch (Throwable $exception) {
            throw new ConfigurationFetchingException(
                'Unable to get the OIDC configuration from ' . $url . ': ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        $this->openidConfig = ['url' => $url, 'config' => $config];

        return $this->openidConfig['config'];
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
     * Get user data by the response from the provider.
     */
    protected function getUserByTokenResponse(#[SensitiveParameter] array $response): array
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
    protected function getUserByOIDCToken(#[SensitiveParameter] string $token): array
    {
        $data = $this->decodeUsingJwks($token);

        $this->validateOIDCPayload($data);

        return $data;
    }

    /**
     * Validate the OIDC payload.
     */
    protected function validateOIDCPayload(array $data): void
    {
        if ($this->usesNonce() && (! isset($data['nonce']) || $this->isInvalidNonce($data['nonce']))) {
            throw new InvalidNonceException;
        }

        $this->validateAudience($data['aud'] ?? null);

        if (! isset($data['iss']) || $data['iss'] !== $this->getOpenIdConfig()['issuer']) {
            throw new InvalidIssuerException;
        }
    }

    /**
     * Get the raw user for the given access token.
     */
    protected function getUserByToken(#[SensitiveParameter] string $token): array
    {
        if (! $userInfoUrl = $this->getUserInfoUrl()) {
            throw new InvalidUserInfoUrlException;
        }

        $response = $this->getHttpClient()->get($userInfoUrl, [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }
}
