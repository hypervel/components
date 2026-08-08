<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use GuzzleHttp\RequestOptions;
use Hypervel\Socialite\Two\Concerns\InteractsWithJwks;
use Hypervel\Socialite\Two\Exceptions\InvalidIssuerException;
use Hypervel\Support\Arr;
use SensitiveParameter;

class GoogleProvider extends AbstractProvider implements ProviderInterface
{
    use InteractsWithJwks;

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

    protected function getUserByToken(#[SensitiveParameter] string $token): array
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
    protected function isJwtToken(#[SensitiveParameter] string $token): bool
    {
        return substr_count($token, '.') === 2 && strlen($token) > 100;
    }

    /**
     * Get user data from a Google ID token (JWT).
     */
    protected function getUserFromJwtToken(#[SensitiveParameter] string $idToken): array
    {
        $user = $this->decodeUsingJwks($idToken);

        if (! isset($user['iss']) || ! in_array($user['iss'], [
            'accounts.google.com',
            'https://accounts.google.com',
        ], true)) {
            throw new InvalidIssuerException;
        }

        $this->validateAudience($user['aud'] ?? null);

        return $user;
    }

    /**
     * Get Google's JSON Web Key Set URI.
     */
    protected function getJwksUri(bool $refresh = false): string
    {
        return 'https://www.googleapis.com/oauth2/v3/certs';
    }
}
