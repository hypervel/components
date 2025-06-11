<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use Exception;
use GuzzleHttp\RequestOptions;
use Hypervel\Support\Arr;

class GithubProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The scopes being requested.
     */
    protected array $scopes = ['user:email'];

    protected function getAuthUrl(string $state): string
    {
        return $this->buildAuthUrlFromBase('https://github.com/login/oauth/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://github.com/login/oauth/access_token';
    }

    protected function getUserByToken(string $token): array
    {
        $userUrl = 'https://api.github.com/user';

        $response = $this->getHttpClient()->get(
            $userUrl,
            $this->getRequestOptions($token)
        );

        $user = json_decode((string) $response->getBody(), true);

        if (in_array('user:email', $this->scopes, true)) {
            $user['email'] = $this->getEmailByToken($token);
        }

        return $user;
    }

    /**
     * Get the email for the given access token.
     */
    protected function getEmailByToken(string $token): ?string
    {
        $emailsUrl = 'https://api.github.com/user/emails';

        try {
            $response = $this->getHttpClient()->get(
                $emailsUrl,
                $this->getRequestOptions($token)
            );
        } catch (Exception $e) {
            return null;
        }

        foreach (json_decode((string) $response->getBody(), true) as $email) {
            if ($email['primary'] && $email['verified']) {
                return $email['email'];
            }
        }

        return null;
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User())->setRaw($user)->map([
            'id' => $user['id'],
            'nodeId' => $user['node_id'],
            'nickname' => $user['login'],
            'name' => Arr::get($user, 'name'),
            'email' => Arr::get($user, 'email'),
            'avatar' => $user['avatar_url'],
        ]);
    }

    /**
     * Get the default options for an HTTP request.
     */
    protected function getRequestOptions(string $token): array
    {
        return [
            RequestOptions::HEADERS => [
                'Accept' => 'application/vnd.github.v3+json',
                'Authorization' => 'token ' . $token,
            ],
        ];
    }
}
