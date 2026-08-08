<?php

declare(strict_types=1);

namespace Hypervel\Jwt;

use Carbon\CarbonInterface;
use Hypervel\Jwt\Contracts\BlacklistContract;
use Hypervel\Jwt\Contracts\StorageContract;
use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Support\Facades\Date;

class Blacklist implements BlacklistContract
{
    public function __construct(
        protected StorageContract $storage,
        protected int $gracePeriod = 0,
        protected ?int $refreshTTL = 20160,
        protected int $leeway = 0,
        protected string $key = 'jti'
    ) {
    }

    /**
     * Add the token (jti claim) to the blacklist.
     */
    public function add(array $payload): bool
    {
        $expiration = $payload['exp'] ?? null;

        if ($expiration === null) {
            return $this->addForeverWithGracePeriod($payload);
        }

        $expiresAt = $this->timestamp($expiration)->addSeconds($this->leeway);
        $issuedAt = $payload['iat'] ?? null;

        // Only a present iat can extend the boundary. Refresh rejects a missing iat
        // before reaching the infinite-refresh return, so expiration alone bounds acceptance.
        if ($issuedAt !== null) {
            if ($this->refreshTTL === null) {
                return $this->addForeverWithGracePeriod($payload);
            }

            $expiresAt = $expiresAt->max(
                $this->timestamp($issuedAt)->addMinutes($this->refreshTTL)
            );
        }

        $expiresAt = $expiresAt->addMinute();
        $now = Date::now();

        // The unified boundary covers expiration acceptance, including leeway, and
        // the refresh window, so terminal tokens need no cache I/O.
        if ($expiresAt <= $now) {
            return true;
        }

        $key = $this->getKey($payload);

        if (! empty($this->storage->get($key))) {
            return true;
        }

        return $this->storage->add(
            $key,
            ['valid_until' => $this->getGraceTimestamp()],
            (int) ceil($now->diffInMinutes($expiresAt)),
        );
    }

    /**
     * Add the token (jti claim) to the blacklist indefinitely.
     */
    public function addForever(array $payload): bool
    {
        return $this->storage->forever($this->getKey($payload), 'forever');
    }

    /**
     * Add the token to the blacklist indefinitely after its grace period.
     */
    protected function addForeverWithGracePeriod(array $payload): bool
    {
        $key = $this->getKey($payload);

        // Rewriting the entry would restart its grace period on every concurrent refresh.
        if (! empty($this->storage->get($key))) {
            return true;
        }

        return $this->storage->forever($key, ['valid_until' => $this->getGraceTimestamp()]);
    }

    /**
     * Determine whether the token has been blacklisted.
     */
    public function has(array $payload): bool
    {
        $value = $this->storage->get($this->getKey($payload));

        // exit early if the token was blacklisted forever,
        if ($value === 'forever') {
            return true;
        }
        if (! $value) {
            return false;
        }

        // check whether the expiry + grace has past
        return ! $this->timestamp($value['valid_until'])->isFuture();
    }

    /**
     * Remove the token (jti claim) from the blacklist.
     */
    public function remove(array $payload): bool
    {
        return $this->storage->destroy($this->getKey($payload));
    }

    /**
     * Remove all tokens from the blacklist.
     */
    public function clear(): bool
    {
        return $this->storage->flush();
    }

    /**
     * Get the timestamp when the blacklist comes into effect
     * This defaults to immediate (0 seconds).
     */
    protected function getGraceTimestamp(): int
    {
        return Date::now()->addSeconds($this->gracePeriod)->getTimestamp();
    }

    /**
     * Set the grace period.
     *
     * Boot-only. Blacklist is a worker-lifetime singleton; runtime mutation
     * affects every subsequent request and races across coroutines.
     *
     * @return $this
     */
    public function setGracePeriod(int $gracePeriod): static
    {
        $this->gracePeriod = $gracePeriod;

        return $this;
    }

    /**
     * Get the grace period.
     */
    public function getGracePeriod(): int
    {
        return $this->gracePeriod;
    }

    /**
     * Get the unique key held within the blacklist.
     */
    public function getKey(array $payload): string
    {
        $key = $payload[$this->key] ?? null;

        if (is_string($key) && $key !== '') {
            return $key;
        }

        if (is_int($key)) {
            return (string) $key;
        }

        throw new TokenInvalidException("Claim `{$this->key}` is missing or invalid in payload for blacklist");
    }

    /**
     * Set the unique key held within the blacklist.
     *
     * Boot-only. Blacklist is a worker-lifetime singleton; runtime mutation
     * affects every subsequent request and races across coroutines.
     *
     * @return $this
     */
    public function setKey(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Set the refresh time limit.
     *
     * Boot-only. Blacklist is a worker-lifetime singleton; runtime mutation
     * affects every subsequent request and races across coroutines.
     *
     * @return $this
     */
    public function setRefreshTTL(?int $ttl): static
    {
        $this->refreshTTL = $ttl;

        return $this;
    }

    /**
     * Get the refresh time limit.
     */
    public function getRefreshTTL(): ?int
    {
        return $this->refreshTTL;
    }

    protected function timestamp(int $timestamp): CarbonInterface
    {
        return Date::createFromTimestamp($timestamp);
    }
}
