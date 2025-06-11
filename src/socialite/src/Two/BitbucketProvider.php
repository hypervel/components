<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use Exception;
use GuzzleHttp\RequestOptions;
use Hypervel\Support\Arr;

class BitbucketProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The scopes being requested.
     */
    protected array $scopes = ['email'];

    /**
     * The separating character for the requested scopes.
     */
    protected string $scopeSeparator = ' ';

    protected function getAuthUrl(string $state): string
    {
        return $this->buildAuthUrlFromBase('https://bitbucket.org/site/oauth2/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return 'https://bitbucket.org/site/oauth2/access_token';
    }

    protected function getUserByToken(string $token): array
    {
        $response = $this->getHttpClient()->get('https://api.bitbucket.org/2.0/user', [
            RequestOptions::QUERY => ['access_token' => $token],
        ]);

        $user = json_decode((string) $response->getBody(), true);

        if (in_array('email', $this->scopes, true)) {
            $user['email'] = $this->getEmailByToken($token);
        }

        return $user;
    }

    /**
     * Get the email for the given access token.
     */
    protected function getEmailByToken(string $token): ?string
    {
        $emailsUrl = 'https://api.bitbucket.org/2.0/user/emails?access_token=' . $token;

        try {
            $response = $this->getHttpClient()->get($emailsUrl);
        } catch (Exception $e) {
            return null;
        }

        $emails = json_decode((string) $response->getBody(), true);

        foreach ($emails['values'] as $email) {
            if ($email['type'] === 'email' && $email['is_primary'] && $email['is_confirmed']) {
                return $email['email'];
            }
        }

        return null;
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User())->setRaw($user)->map([
            'id' => $user['uuid'],
            'nickname' => $user['username'],
            'name' => Arr::get($user, 'display_name'),
            'email' => Arr::get($user, 'email'),
            'avatar' => Arr::get($user, 'links.avatar.href'),
        ]);
    }

    /**
     * Get the access token for the given code.
     */
    public function getAccessToken(string $code): string
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::AUTH => [$this->clientId, $this->clientSecret],
            RequestOptions::HEADERS => ['Accept' => 'application/json'],
            RequestOptions::FORM_PARAMS => $this->getTokenFields($code),
        ]);

        return json_decode((string) $response->getBody(), true)['access_token'];
    }
}
