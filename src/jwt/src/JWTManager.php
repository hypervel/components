<?php

declare(strict_types=1);

namespace Hypervel\JWT;

use Carbon\Carbon;
use Hyperf\Collection\Collection;
use Hyperf\Context\Context;
use Hyperf\Stringable\Str;
use Hypervel\JWT\Contracts\BlacklistContract;
use Hypervel\JWT\Contracts\ManagerContract;
use Hypervel\JWT\Contracts\ValidationContract;
use Hypervel\JWT\Exceptions\JWTException;
use Hypervel\JWT\Exceptions\TokenBlacklistedException;
use Hypervel\JWT\Exceptions\TokenExpiredException;
use Hypervel\JWT\Exceptions\TokenInvalidException;
use Hypervel\JWT\Providers\Lcobucci;
use Hypervel\JWT\Validations\ExpiredClaim;
use Hypervel\Support\Manager;
use Psr\Container\ContainerInterface;
use RuntimeException;

class JWTManager extends Manager implements ManagerContract
{
    protected const REFRESHING_CONTEXT_KEY = '__jwt.refreshing';

    protected ?BlacklistContract $blacklist;

    protected bool $blacklistEnabled = false;

    protected array $validations = [];

    /**
     * Create a new manager instance.
     */
    public function __construct(
        protected ContainerInterface $container
    ) {
        parent::__construct($container);
        $this->blacklist = $container->get(BlacklistContract::class);
        $this->blacklistEnabled = $this->config->get('jwt.blacklist_enabled', false);
    }

    /**
     * Create an instance of the Lcobucci JWT Driver.
     */
    public function createLcobucciDriver(): Lcobucci
    {
        $class = $this->config->get('jwt.providers.jwt', Lcobucci::class);

        if (! is_a($class, Lcobucci::class, true)) {
            throw new RuntimeException('JWT provider must be an instance of ' . Lcobucci::class);
        }

        return new $class(
            (string) $this->config->get('jwt.secret'),
            (string) $this->config->get('jwt.algo'),
            (array) $this->config->get('jwt.keys'),
        );
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('jwt.driver', 'lcobucci');
    }

    public function encode(array $payload): string
    {
        if ($this->blacklistEnabled) {
            $payload['jti'] = (string) Str::uuid();
        }

        return $this->driver()->encode($payload);
    }

    public function decode(string $token, bool $validate = true, bool $checkBlacklist = true): array
    {
        $payload = $this->driver()->decode($token);

        if ($validate) {
            $this->validatePayload($payload);
        }

        if ($this->blacklistEnabled && $checkBlacklist && $this->blacklist->has($payload)) {
            throw new TokenBlacklistedException('The token has been blacklisted');
        }

        return $payload;
    }

    protected function validatePayload(array $payload): void
    {
        $refreshing = Context::get(static::REFRESHING_CONTEXT_KEY, false);

        foreach ($this->config->get('jwt.validations', []) as $validation) {
            $validation = $this->getValidation($validation);

            // Expired tokens can still be refreshed within the refresh window.
            if ($refreshing && $validation instanceof ExpiredClaim) {
                continue;
            }

            $validation->validate($payload);
        }
    }

    protected function getValidation(string $class): ValidationContract
    {
        if ($validation = ($this->validations[$class] ?? null)) {
            return $validation;
        }

        return $this->validations[$class] = new $class($this->config->get('jwt'));
    }

    public function refresh(string $token, bool $forceForever = false): string
    {
        $payload = $this->decodeForRefresh($token);
        $this->validateRefreshWindow($payload);

        // The refreshed token's expiration never comes from the persistent claims.
        $claims = $this->buildRefreshClaims($payload);
        unset($claims['exp']);
        if (! is_null($expiration = $this->getRefreshExpiration())) {
            $claims['exp'] = $expiration;
        }

        if ($this->blacklistEnabled) {
            // Invalidate old token
            $this->invalidate($token, $forceForever);
        }

        // Return the new token
        return $this->encode($claims);
    }

    public function invalidate(string $token, bool $forceForever = false): bool
    {
        if (! $this->blacklistEnabled) {
            throw new JWTException('You must have the blacklist enabled to invalidate a token.');
        }

        return call_user_func(
            [$this->blacklist, $forceForever ? 'addForever' : 'add'],
            $this->decode($token, false)
        );
    }

    /**
     * Decode a token for refresh, allowing it to be expired.
     */
    protected function decodeForRefresh(string $token): array
    {
        // The manager is shared between coroutines, so the flag must be coroutine-local.
        Context::set(static::REFRESHING_CONTEXT_KEY, true);

        try {
            return $this->decode($token);
        } finally {
            Context::destroy(static::REFRESHING_CONTEXT_KEY);
        }
    }

    /**
     * Ensure the token is still within the refresh window of the original token.
     */
    protected function validateRefreshWindow(array $payload): void
    {
        if (! isset($payload['iat'])) {
            throw new TokenInvalidException('Issued At (iat) claim is required to refresh a token.');
        }

        if (is_null($refreshTtl = $this->config->get('jwt.refresh_ttl', 20160))) {
            return;
        }

        // Leeway is not applied here because blacklist retention does not include it.
        $refreshableUntil = Carbon::createFromTimestamp($payload['iat'])
            ->addMinutes((int) $refreshTtl);

        if (Carbon::now()->gt($refreshableUntil)) {
            throw new TokenExpiredException('Token has expired and can no longer be refreshed');
        }
    }

    /**
     * Get the expiration timestamp for a refreshed token, or null if tokens should not expire.
     */
    protected function getRefreshExpiration(): ?int
    {
        if (is_null($ttl = $this->config->get('jwt.ttl', 120))) {
            return null;
        }

        return Carbon::now()->addMinutes((int) $ttl)->getTimestamp();
    }

    protected function buildRefreshClaims(array $payload): array
    {
        // Get the claims to be persisted from the payload
        $persistentClaims = Collection::make($payload)
            ->only($this->config->get('jwt.persistent_claims', []))
            ->toArray();

        // persist the relevant claims
        return array_merge(
            $persistentClaims,
            [
                'sub' => $payload['sub'],
                'iat' => $payload['iat'],
            ]
        );
    }
}
