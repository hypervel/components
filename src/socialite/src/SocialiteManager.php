<?php

declare(strict_types=1);

namespace Hypervel\Socialite;

use Hypervel\Socialite\Contracts\Provider;
use Hypervel\Socialite\Exceptions\DriverMissingConfigurationException;
use Hypervel\Socialite\Two\AbstractProvider as OAuth2Provider;
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
use SensitiveParameter;
use UnitEnum;

class SocialiteManager extends Manager implements Contracts\Factory
{
    /**
     * Get a driver instance.
     */
    public function with(string $driver): Provider
    {
        return $this->driver($driver);
    }

    /**
     * Get a driver instance.
     *
     * Refreshes the request on cached providers so each coroutine
     * gets the current request, not a stale one from first resolution.
     */
    public function driver(UnitEnum|string|null $driver = null): Provider
    {
        $provider = parent::driver($driver);

        if ($provider instanceof AbstractProvider) {
            $provider->setRequest($this->container->make('request'));
        }

        return $provider;
    }

    /**
     * Create an instance of the specified driver.
     */
    protected function createGithubDriver(): GithubProvider
    {
        $config = $this->config->get('services.github');

        return $this->buildOAuth2Provider(
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

        return $this->buildOAuth2Provider(
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

        return $this->buildOAuth2Provider(
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

        return $this->buildOAuth2Provider(
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

        return $this->buildOAuth2Provider(
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

        return $this->buildOAuth2Provider(
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

        return $this->buildOAuth2Provider(
            GitlabProvider::class,
            $config
        );
    }

    // REMOVED: OAuth 1 and legacy Twitter providers are unsupported; use the X OAuth 2 driver.

    /**
     * Create an instance of the specified driver.
     */
    protected function createXDriver(): XProvider
    {
        $config = $this->config->get('services.x') ?? $this->config->get('services.x-oauth-2');

        return $this->buildOAuth2Provider(
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

        return $this->buildOAuth2Provider(
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

        return $this->buildOAuth2Provider(
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

        return $this->buildOAuth2Provider(
            SlackOpenIdProvider::class,
            $config
        );
    }

    /**
     * Build an OAuth 2 provider instance.
     *
     * @template TProvider of OAuth2Provider
     *
     * @param class-string<TProvider> $provider
     * @return TProvider
     */
    public function buildOAuth2Provider(string $provider, #[SensitiveParameter] ?array $config): OAuth2Provider
    {
        $requiredKeys = ['client_id', 'client_secret', 'redirect'];

        $missingKeys = array_diff($requiredKeys, array_keys($config ?? []));

        if (! empty($missingKeys)) {
            throw DriverMissingConfigurationException::make($provider, $missingKeys);
        }

        return (new $provider(
            $this->container->make('request'),
            $config['client_id'],
            $config['client_secret'],
            $this->formatRedirectUrl($config),
            Arr::get($config, 'guzzle', [])
        ))->withConfig($config);
    }

    /**
     * Format the callback URL, resolving a relative URI if needed.
     */
    protected function formatRedirectUrl(#[SensitiveParameter] array $config): string
    {
        $redirect = value($config['redirect']);

        return Str::startsWith($redirect ?? '', '/')
            ? $this->container->make('url')->to($redirect)
            : $redirect;
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
