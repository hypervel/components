<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Closure;
use DateTimeImmutable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Data\CachedToken;
use Hypervel\Saloon\Http\PendingRequest;
use SensitiveParameter;

class CachedTokenAuthenticator implements Authenticator
{
    protected readonly string $cacheKey;

    protected readonly string $store;

    /** @var Closure(): OAuthAuthenticator */
    protected readonly Closure $resolver;

    protected ?CachedToken $token = null;

    /**
     * Create an authenticator for an independently reacquirable grant.
     *
     * @param callable(): OAuthAuthenticator $resolver
     */
    public function __construct(#[SensitiveParameter] string $key, callable $resolver, ?string $store = null)
    {
        $container = Container::getInstance();
        $config = $container->make(ConfigRepository::class);
        $this->store = $store ?? $config->get('saloon.cache.store') ?? $config->string('cache.default');
        $this->cacheKey = 'saloon:oauth-token:' . hash('sha256', $key);
        $this->resolver = $resolver(...);
    }

    /**
     * Apply a cached token, acquiring one when needed.
     */
    public function set(PendingRequest $pendingRequest): void
    {
        $token = $this->token;

        if ($token === null || ! $token->isUsable()) {
            $token = Container::getInstance()->make(TokenAcquisition::class)->run(
                $this->store . ':' . $this->cacheKey,
                function (): CachedToken {
                    $cache = $this->cache();
                    $token = $cache->get($this->cacheKey);

                    if ($token instanceof CachedToken && $token->isUsable()) {
                        return $token;
                    }

                    ConnectionResolver::releaseIdleConnections();
                    $token = new CachedToken(($this->resolver)());
                    $cache->put(
                        $this->cacheKey,
                        $token,
                        $token->refreshAt === null ? null : new DateTimeImmutable('@' . $token->refreshAt),
                    );

                    return $token;
                },
            );

            $this->token = $token;
        }

        $token->authenticator->set($pendingRequest);
    }

    /**
     * Forget this instance's token and the shared cache entry.
     */
    public function forget(): void
    {
        $this->token = null;
        $this->cache()->forget($this->cacheKey);
    }

    /**
     * Resolve the selected token cache.
     */
    protected function cache(): CacheRepository
    {
        return Container::getInstance()->make(CacheFactory::class)->store($this->store);
    }
}
