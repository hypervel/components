<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use GuzzleHttp\RequestOptions;
use Hypervel\Support\Arr;

class SlackOpenIdProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The scopes being requested.
     */
    protected array $scopes = ['openid', 'email', 'profile'];

    /**
     * The separating character for the requested scopes.
     */
    protected string $scopeSeparator = ' ';

    protected function getAuthUrl(string $state): string
    {
        return $this->buildAuthUrlFromBase('https://slack.com/openid/connect/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://slack.com/api/openid.connect.token';
    }

    protected function getUserByToken(string $token): array
    {
        $response = $this->getHttpClient()->get('https://slack.com/api/openid.connect.userInfo', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer ' . $token],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User())->setRaw($user)->map([
            'id' => Arr::get($user, 'sub'),
            'nickname' => null,
            'name' => Arr::get($user, 'name'),
            'email' => Arr::get($user, 'email'),
            'avatar' => Arr::get($user, 'picture'),
            'organization_id' => Arr::get($user, 'https://slack.com/team_id'),
        ]);
    }
}
