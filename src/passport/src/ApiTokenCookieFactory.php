<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Firebase\JWT\JWT;
use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Contracts\Container\Container;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Cookie;

class ApiTokenCookieFactory
{
    /**
     * Create an API token cookie factory instance.
     */
    public function __construct(
        protected Config $config,
        protected ServerFactory $servers,
        protected AuthManager $auth,
        protected Container $app,
    ) {
    }

    /**
     * Create a new API token cookie.
     */
    public function make(string|int $userId, string $csrfToken, ?string $guard = null): Cookie
    {
        $config = $this->getCookieConfig();

        $expiration = Date::now()->addMinutes($this->config->integer('session.lifetime'))->getTimestamp();

        return new Cookie(
            Passport::cookie(),
            $this->createToken($userId, $csrfToken, $expiration, $this->auth->getUserProviderName($guard)),
            $expiration,
            $config['path'],
            $config['domain'],
            $config['secure'],
            true,
            false,
            $config['same_site'],
            $config['partitioned']
        );
    }

    /**
     * Expire the API token cookie for the current request.
     */
    public function forget(): Cookie
    {
        $config = $this->getCookieConfig();

        // CookieJar's domain fallback would replace an explicitly host-only domain.
        return new Cookie(
            Passport::cookie(),
            null,
            1,
            $config['path'],
            $config['domain'],
            $config['secure'],
            true,
            false,
            $config['same_site'],
            $config['partitioned']
        );
    }

    /**
     * Resolve the session cookie attributes for the current request.
     *
     * @return array{path: string, domain: ?string, secure: ?bool, http_only: bool, same_site: ?string, partitioned: bool}
     */
    protected function getCookieConfig(): array
    {
        return StartSession::resolveCookieConfig($this->app->make('request'), [
            'path' => $this->config->string('session.path'),
            'domain' => $this->config->get('session.domain'),
            'secure' => $this->config->get('session.secure'),
            'http_only' => true,
            'same_site' => $this->config->get('session.same_site'),
            'partitioned' => $this->config->boolean('session.partitioned'),
        ]);
    }

    /**
     * Create a new JWT token for the given user ID and CSRF token.
     */
    protected function createToken(string|int $userId, string $csrfToken, int $expiration, ?string $provider): string
    {
        $claims = [
            'sub' => $userId,
            'csrf' => $csrfToken,
            'exp' => $expiration,
            'provider' => $provider,
        ];

        if ($callback = Passport::$cookieBindingCallback) {
            $claims['bind'] = $callback($this->app->make('request'));
        }

        return JWT::encode($claims, $this->servers->cookieKey(), 'HS256');
    }
}
