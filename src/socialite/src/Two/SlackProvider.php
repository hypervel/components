<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use GuzzleHttp\RequestOptions;
use Hypervel\Support\Arr;

class SlackProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The scopes being requested.
     */
    protected array $scopes = ['identity.basic', 'identity.email', 'identity.team', 'identity.avatar'];

    /**
     * The key used for scopes.
     */
    protected string $scopeKey = 'user_scope';

    /**
     * Indicate that the requested token should be for a bot user.
     */
    public function asBotUser(): static
    {
        $this->scopeKey = 'scope';

        return $this;
    }

    public function getAuthUrl(string $state): string
    {
        return $this->buildAuthUrlFromBase('https://slack.com/oauth/v2/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://slack.com/api/oauth.v2.access';
    }

    protected function getUserByToken(string $token): array
    {
        $response = $this->getHttpClient()->get('https://slack.com/api/users.identity', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer ' . $token],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User())->setRaw($user)->map([
            'id' => Arr::get($user, 'user.id'),
            'name' => Arr::get($user, 'user.name'),
            'email' => Arr::get($user, 'user.email'),
            'avatar' => Arr::get($user, 'user.image_512'),
            'organization_id' => Arr::get($user, 'team.id'),
        ]);
    }

    protected function getCodeFields(?string $state = null): array
    {
        $fields = parent::getCodeFields($state);

        if ($this->scopeKey === 'user_scope') {
            $fields['scope'] = '';
            $fields['user_scope'] = $this->formatScopes($this->scopes, $this->scopeSeparator);
        }

        return $fields;
    }

    public function getAccessTokenResponse(string $code): array
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::HEADERS => $this->getTokenHeaders($code),
            RequestOptions::FORM_PARAMS => $this->getTokenFields($code),
        ]);

        $result = json_decode((string) $response->getBody(), true);

        if ($this->scopeKey === 'user_scope') {
            return $result['authed_user'];
        }

        return $result;
    }
}
