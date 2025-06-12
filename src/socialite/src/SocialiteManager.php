<?php

declare(strict_types=1);

namespace Hypervel\Socialite;

use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Http\Contracts\ResponseContract;
use Hypervel\Router\Contracts\UrlGenerator as UrlGeneratorContract;
use Hypervel\Socialite\Exceptions\DriverMissingConfigurationException;
use Hypervel\Socialite\Two\BitbucketProvider;
use Hypervel\Socialite\Two\FacebookProvider;
use Hypervel\Socialite\Two\GithubProvider;
use Hypervel\Socialite\Two\GitlabProvider;
use Hypervel\Socialite\Two\GoogleProvider;
use Hypervel\Socialite\Two\LinkedInOpenIdProvider;
use Hypervel\Socialite\Two\LinkedInProvider;
use Hypervel\Socialite\Two\SlackOpenIdProvider;
use Hypervel\Socialite\Two\SlackProvider;
use Hypervel\Socialite\Two\TwitchProvider;
use Hypervel\Socialite\Two\XProvider;
use Hypervel\Support\Arr;
use Hypervel\Support\Manager;
use Hypervel\Support\Str;
use InvalidArgumentException;

class SocialiteManager extends Manager implements Contracts\Factory
{
    /**
     * Get a driver instance.
     */
    public function with(string $driver): mixed
    {
        return $this->driver($driver);
    }

    /**
     * Create an instance of the specified driver.
     */
    protected function createGithubDriver(): GithubProvider
    {
        $config = $this->config->get('services.github');

        return $this->buildProvider(
            GithubProvider::class,
            $config
        );
    }

    /**
     * Create an instance of the specified driver.
     */
    protected function createFacebookDriver(): FacebookProvider
    {
        $config = $this->config->get('services.facebook');

        return $this->buildProvider(
            FacebookProvider::class,
            $config
        );
    }

    /**
     * Create an instance of the specified driver.
     */
    protected function createGoogleDriver(): GoogleProvider
    {
        $config = $this->config->get('services.google');

        return $this->buildProvider(
            GoogleProvider::class,
            $config
        );
    }

    /**
     * Create an instance of the specified driver.
     */
    protected function createLinkedinDriver(): LinkedInProvider
    {
        $config = $this->config->get('services.linkedin');

        return $this->buildProvider(
            LinkedInProvider::class,
            $config
        );
    }

    /**
     * Create an instance of the specified driver.
     */
    protected function createLinkedinOpenidDriver(): LinkedInOpenIdProvider
    {
        $config = $this->config->get('services.linkedin-openid');

        return $this->buildProvider(
            LinkedInOpenIdProvider::class,
            $config
        );
    }

    /**
     * Create an instance of the specified driver.
     */
    protected function createBitbucketDriver(): BitbucketProvider
    {
        $config = $this->config->get('services.bitbucket');

        return $this->buildProvider(
            BitbucketProvider::class,
            $config
        );
    }

    /**
     * Create an instance of the specified driver.
     */
    protected function createGitlabDriver(): GitlabProvider
    {
        $config = $this->config->get('services.gitlab');

        return $this->buildProvider(
            GitlabProvider::class,
            $config
        )->setHost($config['host'] ?? null); // phpstan-ignore-line
    }

    /**
     * Create an instance of the specified driver.
     */
    protected function createXDriver(): XProvider
    {
        $config = $this->config->get('services.x') ?? $this->config->get('services.x-oauth-2');

        return $this->buildProvider(
            XProvider::class,
            $config
        );
    }

    /**
     * Create an instance of the specified driver.
     */
    protected function createTwitchDriver(): TwitchProvider
    {
        $config = $this->config->get('services.twitch');

        return $this->buildProvider(
            TwitchProvider::class,
            $config
        );
    }

    /**
     * Create an instance of the specified driver.
     */
    protected function createSlackDriver(): SlackProvider
    {
        $config = $this->config->get('services.slack');

        return $this->buildProvider(
            SlackProvider::class,
            $config
        );
    }

    /**
     * Create an instance of the specified driver.
     */
    protected function createSlackOpenidDriver(): SlackOpenIdProvider
    {
        $config = $this->config->get('services.slack-openid');

        return $this->buildProvider(
            SlackOpenIdProvider::class,
            $config
        );
    }

    /**
     * Build an OAuth 2 provider instance.
     */
    public function buildProvider(string $provider, ?array $config): mixed
    {
        $requiredKeys = ['client_id', 'client_secret', 'redirect'];

        $missingKeys = array_diff($requiredKeys, array_keys($config ?? []));

        if (! empty($missingKeys)) {
            throw DriverMissingConfigurationException::make($provider, $missingKeys);
        }

        return (new $provider(
            $this->container->get(RequestContract::class),
            $this->container->get(ResponseContract::class),
            $config['client_id'],
            $config['client_secret'],
            $this->formatRedirectUrl($config),
            Arr::get($config, 'guzzle', [])
        ))->scopes($config['scopes'] ?? []);
    }

    /**
     * Format the server configuration.
     */
    public function formatConfig(array $config): array
    {
        return array_merge([
            'identifier' => $config['client_id'],
            'secret' => $config['client_secret'],
            'callback_uri' => $this->formatRedirectUrl($config),
        ], $config);
    }

    /**
     * Format the callback URL, resolving a relative URI if needed.
     */
    protected function formatRedirectUrl(array $config): string
    {
        $redirect = value($config['redirect']);

        return Str::startsWith($redirect ?? '', '/')
            ? $this->container->get(UrlGeneratorContract::class)->to($redirect)
            : $redirect;
    }

    /**
     * Forget all of the resolved driver instances.
     */
    public function forgetDrivers(): static
    {
        $this->drivers = [];

        return $this;
    }

    /**
     * Get the default driver name.
     *
     * @throws InvalidArgumentException
     */
    public function getDefaultDriver(): string
    {
        throw new InvalidArgumentException('No Socialite driver was specified.');
    }
}
