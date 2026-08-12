<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use GuzzleHttp\RequestOptions;
use Hypervel\Support\Arr;
use SensitiveParameter;

class TwitchProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The scopes being requested.
     */
    protected array $scopes = ['user:read:email'];

    /**
     * The separating character for the requested scopes.
     */
    protected string $scopeSeparator = ' ';

    protected function getAuthUrl(?string $state): string
    {
        return $this->buildAuthUrlFromBase('https://id.twitch.tv/oauth2/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://id.twitch.tv/oauth2/token';
    }

    protected function getUserByToken(#[SensitiveParameter] string $token): array
    {
        $response = $this->getHttpClient()->get(
            'https://api.twitch.tv/helix/users',
            [
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                    'Client-ID' => $this->getClientId(),
                ],
            ]
        );

        return json_decode((string) $response->getBody(), true);
    }

    protected function mapUserToObject(array $user): User
    {
        $user = $user['data']['0'];

        return (new User)->setRaw($user)->map([
            'id' => $user['id'],
            'nickname' => $user['display_name'],
            'name' => $user['display_name'],
            'email' => Arr::get($user, 'email'),
            'avatar' => $user['profile_image_url'],
        ]);
    }
}
