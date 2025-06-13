<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use GuzzleHttp\RequestOptions;
use Hypervel\Support\Arr;

class XProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The scopes being requested.
     */
    protected array $scopes = ['users.read', 'users.email', 'tweet.read'];

    /**
     * Indicates if PKCE should be used.
     */
    protected bool $usesPKCE = true;

    /**
     * The separating character for the requested scopes.
     */
    protected string $scopeSeparator = ' ';

    /**
     * The query encoding format.
     */
    protected int $encodingType = PHP_QUERY_RFC3986;

    public function getAuthUrl(?string $state): string
    {
        return $this->buildAuthUrlFromBase('https://x.com/i/oauth2/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://api.x.com/2/oauth2/token';
    }

    protected function getUserByToken(string $token): array
    {
        $response = $this->getHttpClient()->get('https://api.x.com/2/users/me', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer ' . $token],
            RequestOptions::QUERY => ['user.fields' => 'profile_image_url,confirmed_email'],
        ]);

        return Arr::get(json_decode((string) $response->getBody(), true), 'data');
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User())->setRaw($user)->map([
            'id' => $user['id'],
            'email' => $user['confirmed_email'] ?? null,
            'nickname' => $user['username'],
            'name' => $user['name'],
            'avatar' => $user['profile_image_url'],
        ]);
    }

    public function getAccessTokenResponse(string $code): array
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::HEADERS => ['Accept' => 'application/json'],
            RequestOptions::AUTH => [$this->clientId, $this->clientSecret],
            RequestOptions::FORM_PARAMS => $this->getTokenFields($code),
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    protected function getRefreshTokenResponse(string $refreshToken): array
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::HEADERS => ['Accept' => 'application/json'],
            RequestOptions::AUTH => [$this->clientId, $this->clientSecret],
            RequestOptions::FORM_PARAMS => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    protected function getCodeFields(?string $state = null): array
    {
        $fields = parent::getCodeFields($state);

        if ($this->isStateless()) {
            $fields['state'] = 'state';
        }

        return $fields;
    }
}
