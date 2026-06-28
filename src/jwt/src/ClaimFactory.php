<?php

declare(strict_types=1);

namespace Hypervel\JWT;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\JWT\Contracts\JWTSubject;
use Hypervel\JWT\Exceptions\JWTException;
use Hypervel\Support\Facades\Date;

class ClaimFactory
{
    /**
     * Claims stamped by the factory itself when a token is refreshed.
     */
    protected const array MANAGED_REFRESH_CLAIMS = ['iat', 'nbf', 'exp', 'iss', 'jti'];

    /**
     * Claims owned by the guard, manager, or claim factory.
     */
    protected const array RESERVED_CUSTOM_CLAIMS = ['sub', 'prv', ...self::MANAGED_REFRESH_CLAIMS];

    protected static array $subjectModelHashes = [];

    protected ?string $issuer;

    protected bool $lockSubject;

    /**
     * Create a new claim factory.
     */
    public function __construct(Repository $config)
    {
        /** @var null|string $issuer */
        $issuer = $config->get('jwt.issuer');

        $this->issuer = ($issuer === null || $issuer === '') ? null : $issuer;
        $this->lockSubject = $config->boolean('jwt.lock_subject', true);
    }

    /**
     * Build claims for a newly issued token.
     */
    public function make(
        Authenticatable $user,
        UserProvider $provider,
        ?int $ttl,
        array $customClaims = [],
    ): array {
        $claims = [
            'sub' => $this->subjectIdentifier($user),
        ];

        if ($this->lockSubject && method_exists($provider, 'getModel')) {
            /* @phpstan-ignore-next-line method.notFound */
            $claims['prv'] = $this->subjectModelHash($provider->getModel());
        }

        if ($user instanceof JWTSubject) {
            $subjectClaims = $user->getJWTCustomClaims();
            $this->rejectReservedCustomClaims($subjectClaims);

            $claims = array_merge($claims, $subjectClaims);
        }

        $this->rejectReservedCustomClaims($customClaims);

        return $this->withDefaults(array_merge($claims, $customClaims), $ttl);
    }

    /**
     * Build claims for a refreshed token.
     */
    public function refresh(
        array $payload,
        ?int $ttl,
        bool $refreshIssuedAt,
        bool $resetClaims,
        array $persistentClaims,
        array $customClaims = [],
    ): array {
        $managed = array_flip(self::MANAGED_REFRESH_CLAIMS);
        $persistent = array_diff_key(
            array_intersect_key($payload, array_flip($persistentClaims)),
            $managed,
        );

        $claims = $resetClaims
            ? $persistent
            : array_diff_key($payload, $managed);

        $this->rejectReservedCustomClaims($customClaims);

        $claims = array_merge($claims, $persistent, $customClaims, [
            'sub' => $payload['sub'],
        ]);

        if (! $refreshIssuedAt) {
            $claims['iat'] = $payload['iat'];
        }

        if (array_key_exists('prv', $payload)) {
            $claims['prv'] = $payload['prv'];
        }

        return $this->withDefaults($claims, $ttl);
    }

    /**
     * Determine the subject identifier for a user.
     */
    public function subjectIdentifier(Authenticatable $user): mixed
    {
        return $user instanceof JWTSubject
            ? $user->getJWTIdentifier()
            : $user->getAuthIdentifier();
    }

    /**
     * Check whether a decoded token belongs to the configured provider model.
     */
    public function subjectMatchesProvider(array $payload, UserProvider $provider): bool
    {
        if (! $this->lockSubject || ! method_exists($provider, 'getModel')) {
            return true;
        }

        /* @phpstan-ignore-next-line method.notFound */
        $model = $provider->getModel();

        return isset($payload['prv'])
            && hash_equals($this->subjectModelHash($model), (string) $payload['prv']);
    }

    /**
     * Reject custom claims owned by the package.
     */
    protected function rejectReservedCustomClaims(array $claims): void
    {
        $reserved = array_intersect(array_keys($claims), self::RESERVED_CUSTOM_CLAIMS);

        if ($reserved !== []) {
            sort($reserved);

            throw new JWTException('Custom JWT claims may not override reserved claims: ' . implode(', ', $reserved) . '.');
        }
    }

    /**
     * Stamp standard claims, then apply caller claims on top.
     */
    protected function withDefaults(array $claims, ?int $ttl): array
    {
        $now = Date::now();

        $defaults = [
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
        ];

        if ($ttl !== null) {
            $defaults['exp'] = $now->addMinutes($ttl)->getTimestamp();
        }

        if ($this->issuer !== null) {
            $defaults['iss'] = $this->issuer;
        }

        return array_merge($defaults, $claims);
    }

    /**
     * Hash the subject model class.
     */
    protected function subjectModelHash(string|object $model): string
    {
        $class = is_object($model) ? $model::class : $model;

        return static::$subjectModelHashes[$class] ??= hash('xxh128', $class);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$subjectModelHashes = [];
    }
}
