<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use Exception;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\RequestOptions;
use Hypervel\Support\Arr;

class GoogleProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The separating character for the requested scopes.
     */
    protected string $scopeSeparator = ' ';

    /**
     * The scopes being requested.
     */
    protected array $scopes = [
        'openid',
        'profile',
        'email',
    ];

    protected function getAuthUrl(?string $state): string
    {
        return $this->buildAuthUrlFromBase('https://accounts.google.com/o/oauth2/auth', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://www.googleapis.com/oauth2/v4/token';
    }

    protected function getUserByToken(string $token): array
    {
        if ($this->isJwtToken($token)) {
            return $this->getUserFromJwtToken($token);
        }

        $response = $this->getHttpClient()->get('https://www.googleapis.com/oauth2/v3/userinfo', [
            RequestOptions::QUERY => [
                'prettyPrint' => 'false',
            ],
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    public function refreshToken(string $refreshToken): Token
    {
        $response = $this->getRefreshTokenResponse($refreshToken);

        return new Token(
            Arr::get($response, 'access_token'),
            Arr::get($response, 'refresh_token', $refreshToken),
            Arr::get($response, 'expires_in'),
            explode($this->scopeSeparator, Arr::get($response, 'scope', ''))
        );
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => Arr::get($user, 'sub'),
            'nickname' => Arr::get($user, 'nickname'),
            'name' => Arr::get($user, 'name'),
            'email' => Arr::get($user, 'email'),
            'avatar' => $avatarUrl = Arr::get($user, 'picture'),
            'avatar_original' => $avatarUrl,
        ]);
    }

    /**
     * Determine if the given token is a JWT (ID token).
     */
    protected function isJwtToken(string $token): bool
    {
        return substr_count($token, '.') === 2 && strlen($token) > 100;
    }

    /**
     * Get user data from a Google ID token (JWT).
     *
     * @throws Exception
     */
    protected function getUserFromJwtToken(string $idToken): array
    {
        try {
            $user = (array) JWT::decode(
                $idToken,
                JWK::parseKeySet($this->getGoogleJwks())
            );

            if (! isset($user['iss']) || $user['iss'] !== 'https://accounts.google.com') {
                throw new Exception('Invalid ID token issuer.');
            }

            if (! isset($user['aud']) || $user['aud'] !== $this->getClientId()) {
                throw new Exception('Invalid ID token audience.');
            }

            return $user;
        } catch (Exception $e) {
            throw new Exception('Failed to verify Google JWT token: ' . $e->getMessage());
        }
    }

    /**
     * Get Google's JSON Web Key Set for JWT verification.
     */
    protected function getGoogleJwks(): array
    {
        $response = $this->getHttpClient()->get(
            'https://www.googleapis.com/oauth2/v3/certs'
        );

        return json_decode((string) $response->getBody(), true);
    }
}
