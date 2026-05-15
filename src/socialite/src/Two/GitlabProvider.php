<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Two;

use GuzzleHttp\RequestOptions;

class GitlabProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The scopes being requested.
     */
    protected array $scopes = ['read_user'];

    /**
     * The separating character for the requested scopes.
     */
    protected string $scopeSeparator = ' ';

    /**
     * The Gitlab instance host.
     */
    protected string $host = 'https://gitlab.com';

    /**
     * Set the Gitlab instance host.
     */
    public function setHost(?string $host): static
    {
        if (! empty($host)) {
            $this->setContext('host', rtrim($host, '/'));
        }

        return $this;
    }

    protected function getAuthUrl(?string $state): string
    {
        return $this->buildAuthUrlFromBase($this->getHost() . '/oauth/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->getHost() . '/oauth/token';
    }

    protected function getUserByToken(string $token): array
    {
        $response = $this->getHttpClient()->get($this->getHost() . '/api/v3/user', [
            RequestOptions::QUERY => ['access_token' => $token],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Get the Gitlab instance host.
     */
    protected function getHost(): string
    {
        /** @var string|null $host */
        $host = $this->getContext('host');

        if (! empty($host)) {
            return rtrim($host, '/');
        }

        /** @var string|null $configuredHost */
        $configuredHost = $this->getConfig('host');

        return rtrim(! empty($configuredHost) ? $configuredHost : $this->host, '/');
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['id'],
            'nickname' => $user['username'],
            'name' => $user['name'],
            'email' => $user['email'],
            'avatar' => $user['avatar_url'],
        ]);
    }
}
