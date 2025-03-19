<?php

declare(strict_types=1);

namespace Hypervel\JWT;

use Hyperf\Collection\Collection;
use Hyperf\Stringable\Str;
use Hypervel\JWT\Contracts\BlacklistContract;
use Hypervel\JWT\Contracts\ManagerContract;
use Hypervel\JWT\Contracts\ValidationContract;
use Hypervel\JWT\Exceptions\JWTException;
use Hypervel\JWT\Exceptions\TokenBlacklistedException;
use Hypervel\JWT\Providers\Lcobucci;
use Hypervel\Support\Manager;
use Psr\Container\ContainerInterface;
use RuntimeException;

class JWTManager extends Manager implements ManagerContract
{
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
        foreach ($this->config->get('jwt.validations', []) as $validation) {
            $this->getValidation($validation)
                ->validate($payload);
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
        $claims = $this->buildRefreshClaims($this->decode($token));

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
