<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Mercure;

use Hypervel\Broadcasting\Broadcasters\MercureBroadcaster;
use Hypervel\Contracts\Broadcasting\Broadcaster;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\ObjectPool\PoolDefinition;
use InvalidArgumentException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\DefaultClaimsTokenFactory;
use Symfony\Component\Mercure\Jwt\FactoryTokenProvider;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\WebTokenFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

trait CreatesMercureDrivers
{
    protected const string DEFAULT_MERCURE_ALGORITHM = 'HS256';

    /**
     * Create an instance of the driver.
     */
    protected function createMercureDriver(array $config): Broadcaster
    {
        $expiration = $this->mercureSubscribeExpiration($config);

        if ($expiration <= 0) {
            throw new InvalidArgumentException('The Mercure "subscribe_expiration" configuration value must be a positive number of minutes.');
        }

        $hub = $this->mercure($config);

        if ($hub->getFactory() === null) {
            throw new InvalidArgumentException('The Mercure broadcasting connection requires a "secret" (or "subscribe_secret") configuration value.');
        }

        $cookieName = strtolower($hub->getCookieName());

        if ((str_starts_with($cookieName, '__secure-') || str_starts_with($cookieName, '__host-'))
            && strtolower((string) parse_url((string) (($config['public_url'] ?? null) ?: $config['url']), PHP_URL_SCHEME)) === 'http') {
            throw new InvalidArgumentException(sprintf('The Mercure "%s" cookie requires an "https" hub "public_url". Use HTTPS, or configure a "cookie_name" without the "__Secure-" or "__Host-" prefix for plain-HTTP development.', $hub->getCookieName()));
        }

        return new MercureBroadcaster(
            $this->app,
            $hub,
            $expiration,
            $this->mercureChannelEncrypter($config),
            (string) (($config['topic_prefix'] ?? null) ?: MercureBroadcaster::DEFAULT_TOPIC_PREFIX),
            (bool) ($config['client_events'] ?? true),
        );
    }

    /**
     * Get a Mercure hub instance for the given configuration.
     */
    public function mercure(array $config): HubInterface
    {
        if (empty($config['url'])) {
            throw new InvalidArgumentException('The Mercure broadcasting connection requires a "url" configuration value.');
        }

        $publishExpiration = (int) (($config['publish_expiration'] ?? 0) * 60);

        if ($publishExpiration < 0 || ($publishExpiration === 0 && ! empty($config['publish_expiration']))) {
            throw new InvalidArgumentException('The Mercure "publish_expiration" configuration value must be a positive number of minutes, or 0 to use the default lifetime.');
        }

        $publishTokenFactory = $this->mercureTokenFactory(
            WebTokenFactory::fromSecret(
                $this->mercureSecret($config, 'publish'),
                (string) ($config['publish_algorithm'] ?? $config['algorithm'] ?? self::DEFAULT_MERCURE_ALGORITHM),
                $publishExpiration,
                (string) ($config['publish_passphrase'] ?? $config['passphrase'] ?? ''),
            ),
            $this->mercurePublishClaims($config),
            $config,
        );

        $clientOptions = $config['client_options'] ?? [];

        /** @var UrlGenerator $urls */
        $urls = $this->app->make('url');

        return new Hub(
            $this->mercurePoolDefinition($config),
            static fn (): HttpClientInterface => HttpClient::create($clientOptions),
            $this->poolFactory(),
            $urls,
            (string) $config['url'],
            new CachingTokenProvider(
                new FactoryTokenProvider($publishTokenFactory, [new Grant([Grant::ACTION_PUBLISH], ['*'])]),
                cacheKeyResolver: $publishTokenFactory instanceof AudienceTokenFactory ? $publishTokenFactory->getAudience(...) : null,
            ),
            $this->mercureSubscribeFactory($config),
            ($config['public_url'] ?? null) ?: null,
            ($config['cookie_name'] ?? null) ?: null,
        );
    }

    // REMOVED: frankenPhpMercure() calls FrankenPHP's in-process hub; Hypervel uses Swoole and publishes over HTTP.

    /**
     * Identify the shared HTTP-client pool for construction and invalidation.
     */
    protected function mercurePoolDefinition(array $config): PoolDefinition
    {
        return $this->poolDefinition('mercure-http-client', $config['pool'] ?? [], $config['client_options'] ?? []);
    }

    /**
     * Get the subscriber token (and cookie) lifetime in whole seconds.
     */
    protected function mercureSubscribeExpiration(array $config): int
    {
        return (int) (($config['subscribe_expiration'] ?? 5) * 60);
    }

    /**
     * Get the subscriber token factory for the given Mercure configuration.
     */
    protected function mercureSubscribeFactory(array $config): ?TokenFactoryInterface
    {
        if (empty($config['subscribe_secret']) && empty($config['secret'])) {
            return null;
        }

        $claims = $this->mercureClaims($config);

        $claims['sub'] = ($claims['sub'] ?? null) ?: 'anonymous';

        return $this->mercureTokenFactory(
            WebTokenFactory::fromSecret(
                $this->mercureSecret($config, 'subscribe'),
                (string) ($config['subscribe_algorithm'] ?? $config['algorithm'] ?? self::DEFAULT_MERCURE_ALGORITHM),
                $this->mercureSubscribeExpiration($config),
                (string) ($config['subscribe_passphrase'] ?? $config['passphrase'] ?? ''),
            ),
            $claims,
            $config,
        );
    }

    /**
     * Add configured claims and resolve the default audience when a token is minted.
     */
    protected function mercureTokenFactory(TokenFactoryInterface $factory, array $claims, array $config): TokenFactoryInterface
    {
        $factory = new DefaultClaimsTokenFactory($factory, $claims);

        // Resolve only our default audience; preserve configured and overridden claims.
        if (! empty($config['claims']['aud']) || $claims['aud'] !== (($config['public_url'] ?? null) ?: $config['url'])) {
            return $factory;
        }

        /** @var UrlGenerator $urls */
        $urls = $this->app->make('url');
        $audience = (string) $claims['aud'];

        return new AudienceTokenFactory($factory, static fn (): string => $urls->to($audience));
    }

    /**
     * Get the end-to-end channel encrypter for the given Mercure configuration.
     */
    protected function mercureChannelEncrypter(array $config): ?ChannelEncrypter
    {
        if (empty($config['encryption_key'])) {
            return null;
        }

        $encodedKey = (string) $config['encryption_key'];

        if (str_starts_with($encodedKey, 'base64:')) {
            $encodedKey = substr($encodedKey, 7);
        }

        $key = base64_decode($encodedKey, true);

        if ($key === false || strlen($key) !== 32) {
            throw new InvalidArgumentException('The Mercure "encryption_key" configuration value must be a base64-encoded 32-byte key. You may generate one with: php -r "echo base64_encode(random_bytes(32));"');
        }

        return new ChannelEncrypter($key);
    }

    /**
     * Get the additional JWT claims for the publisher side of the given Mercure configuration.
     */
    protected function mercurePublishClaims(array $config): array
    {
        $claims = $this->mercureClaims($config);

        $claims['sub'] = ($claims['sub'] ?? null) ?: (($claims['client_id'] ?? null) ?: $config['url']);

        return $claims;
    }

    /**
     * Get the JWT secret for the given side ("subscribe" or "publish") of the given Mercure configuration.
     */
    protected function mercureSecret(array $config, string $side): string
    {
        $secret = ($config[$side . '_secret'] ?? null) ?: ($config['secret'] ?? null);

        if (empty($secret)) {
            throw new InvalidArgumentException(sprintf(
                'The Mercure broadcasting connection requires a "secret" (or "%s_secret") configuration value.',
                $side
            ));
        }

        $secret = (string) $secret;
        $algorithm = (string) ($config[$side . '_algorithm'] ?? $config['algorithm'] ?? self::DEFAULT_MERCURE_ALGORITHM);

        $minimumLength = ['HS256' => 32, 'HS384' => 48, 'HS512' => 64][$algorithm] ?? 0;

        if (strlen($secret) < $minimumLength) {
            throw new InvalidArgumentException(sprintf(
                'The Mercure "secret" (or "%s_secret") configuration value must be at least %d bytes long to sign %s tokens.',
                $side,
                $minimumLength,
                $algorithm
            ));
        }

        return $secret;
    }

    /**
     * Get the additional JWT claims for the given Mercure configuration.
     */
    protected function mercureClaims(array $config): array
    {
        $claims = $config['claims'] ?? [];
        $appUrl = $this->app->make('config')->get('app.url');
        $url = ($config['url'] ?? null) ?: (($config['public_url'] ?? null) ?: '/.well-known/mercure');

        $claims['aud'] = ($claims['aud'] ?? null) ?: (($config['public_url'] ?? null) ?: $url);
        $claims['iss'] = ($claims['iss'] ?? null) ?: ($appUrl ?: $url);
        $claims['client_id'] = ($claims['client_id'] ?? null) ?: $claims['iss'];

        return $claims;
    }
}
