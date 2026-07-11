<?php

declare(strict_types=1);

namespace Hypervel\Jwt;

use Hypervel\Contracts\Container\Container;
use Hypervel\Jwt\Contracts\BlacklistContract;
use Hypervel\Jwt\Contracts\ManagerContract;
use Hypervel\Jwt\Contracts\TemporalValidation;
use Hypervel\Jwt\Contracts\ValidationContract;
use Hypervel\Jwt\Exceptions\JwtException;
use Hypervel\Jwt\Exceptions\TokenBlacklistedException;
use Hypervel\Jwt\Exceptions\TokenExpiredException;
use Hypervel\Jwt\Providers\Lcobucci;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Manager;
use Hypervel\Support\Str;
use RuntimeException;

class JwtManager extends Manager implements ManagerContract
{
    protected ?BlacklistContract $blacklist;

    protected bool $blacklistEnabled = false;

    protected array $validations = [];

    /**
     * Create a new manager instance.
     */
    public function __construct(
        Container $container,
        protected ClaimFactory $claimFactory,
    ) {
        parent::__construct($container);

        $this->blacklistEnabled = $this->config->boolean('jwt.blacklist_enabled', false);
        $this->blacklist = $this->blacklistEnabled
            ? $container->make(BlacklistContract::class)
            : null;
    }

    /**
     * Create an instance of the Lcobucci JWT Driver.
     */
    public function createLcobucciDriver(): Lcobucci
    {
        $class = $this->config->string('jwt.providers.jwt', Lcobucci::class);

        if (! is_a($class, Lcobucci::class, true)) {
            throw new RuntimeException('JWT provider must be an instance of ' . Lcobucci::class);
        }

        return new $class(
            (string) $this->config->get('jwt.secret'),
            $this->config->string('jwt.algo'),
            $this->config->array('jwt.keys'),
        );
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->string('jwt.driver', 'lcobucci');
    }

    /**
     * Encode a payload into a token.
     */
    public function encode(array $payload): string
    {
        if ($this->blacklistEnabled && ! array_key_exists('jti', $payload)) {
            $payload['jti'] = (string) Str::uuid();
        }

        return $this->driver()->encode($payload);
    }

    /**
     * Decode a token into its payload.
     */
    public function decode(string $token, bool $validate = true, bool $checkBlacklist = true): array
    {
        $payload = $this->driver()->decode($token);

        if ($validate) {
            $this->validatePayload($payload);
        }

        if ($this->blacklistEnabled && $checkBlacklist && $this->blacklist()->has($payload)) {
            throw new TokenBlacklistedException('The token has been blacklisted');
        }

        return $payload;
    }

    protected function validatePayload(array $payload, bool $refresh = false): void
    {
        foreach ($this->config->array('jwt.validations', []) as $validation) {
            $validation = $this->getValidation($validation);

            if ($refresh && $validation instanceof TemporalValidation) {
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

        return $this->validations[$class] = new $class($this->config->array('jwt'));
    }

    /**
     * Refresh a token.
     */
    public function refresh(
        string $token,
        bool $forceForever = false,
        bool $resetClaims = false,
        array $customClaims = [],
        int|false|null $ttl = false,
    ): string {
        $payload = $this->decodeForRefresh($token);
        $this->validateRefreshWindow($payload);

        if ($ttl === false) {
            /** @var null|int $ttl */
            $ttl = $this->config->get('jwt.ttl', 120);
        }

        $claims = $this->claimFactory->refresh(
            payload: $payload,
            ttl: $ttl,
            refreshIssuedAt: $this->config->boolean('jwt.refresh_iat', false),
            resetClaims: $resetClaims,
            persistentClaims: $this->config->array('jwt.persistent_claims', []),
            customClaims: $customClaims,
        );

        $newToken = $this->encode($claims);

        if ($this->blacklistEnabled) {
            $this->invalidate($token, $forceForever);
        }

        return $newToken;
    }

    /**
     * Decode a token for refresh.
     */
    protected function decodeForRefresh(string $token): array
    {
        $payload = $this->driver()->decode($token);

        $this->validatePayload($payload, refresh: true);

        if ($this->blacklistEnabled && $this->blacklist()->has($payload)) {
            throw new TokenBlacklistedException('The token has been blacklisted');
        }

        return $payload;
    }

    /**
     * Invalidate a token.
     */
    public function invalidate(string $token, bool $forceForever = false): bool
    {
        if (! $this->blacklistEnabled) {
            throw new JwtException('You must have the blacklist enabled to invalidate a token.');
        }

        return call_user_func(
            [$this->blacklist(), $forceForever ? 'addForever' : 'add'],
            $this->decode($token, false, false)
        );
    }

    /**
     * Validate that the token is still refreshable.
     */
    protected function validateRefreshWindow(array $payload): void
    {
        /** @var null|int $refreshTtl */
        $refreshTtl = $this->config->get('jwt.refresh_ttl', 20160);

        if ($refreshTtl === null) {
            return;
        }

        if (Date::now() > Date::createFromTimestamp($payload['iat'])->addMinutes($refreshTtl)) {
            throw new TokenExpiredException('Token has expired and can no longer be refreshed');
        }
    }

    /**
     * Determine if the blacklist is enabled.
     */
    public function hasBlacklistEnabled(): bool
    {
        return $this->blacklistEnabled;
    }

    /**
     * Get the configured blacklist instance.
     */
    protected function blacklist(): BlacklistContract
    {
        if ($this->blacklist === null) {
            throw new JwtException('JWT blacklist is not configured.');
        }

        return $this->blacklist;
    }
}
