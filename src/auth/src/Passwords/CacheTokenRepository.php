<?php

declare(strict_types=1);

namespace Hypervel\Auth\Passwords;

use Hypervel\Cache\Repository;
use Hypervel\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Hypervel\Contracts\Hashing\Hasher as HasherContract;
use Hypervel\Support\Carbon;
use Hypervel\Support\Str;
use SensitiveParameter;

class CacheTokenRepository implements TokenRepositoryInterface
{
    /**
     * The format of the stored Carbon object.
     */
    protected string $format = 'Y-m-d H:i:s';

    /**
     * Create a new token repository instance.
     */
    public function __construct(
        protected Repository $cache,
        protected HasherContract $hasher,
        protected string $hashKey,
        protected int $expires = 3600,
        protected int $throttle = 60,
    ) {
    }

    /**
     * Create a new token.
     */
    public function create(CanResetPasswordContract $user): string
    {
        $this->delete($user);

        $token = hash_hmac('sha256', Str::random(40), $this->hashKey);

        $this->cache->put(
            $this->cacheKey($user),
            [$this->hasher->make($token), Carbon::now()->format($this->format)],
            $this->expires,
        );

        return $token;
    }

    /**
     * Determine if a token record exists and is valid.
     */
    public function exists(CanResetPasswordContract $user, #[SensitiveParameter] string $token): bool
    {
        [$record, $createdAt] = $this->cache->get($this->cacheKey($user));

        return $record
            && ! $this->tokenExpired($createdAt)
            && $this->hasher->check($token, $record);
    }

    /**
     * Determine if the token has expired.
     */
    protected function tokenExpired(string $createdAt): bool
    {
        return Carbon::createFromFormat($this->format, $createdAt)->addSeconds($this->expires)->isPast();
    }

    /**
     * Determine if the given user recently created a password reset token.
     */
    public function recentlyCreatedToken(CanResetPasswordContract $user): bool
    {
        [$record, $createdAt] = $this->cache->get($this->cacheKey($user));

        return $record && $this->tokenRecentlyCreated($createdAt);
    }

    /**
     * Determine if the token was recently created.
     */
    protected function tokenRecentlyCreated(string $createdAt): bool
    {
        if ($this->throttle <= 0) {
            return false;
        }

        return Carbon::createFromFormat($this->format, $createdAt)->addSeconds(
            $this->throttle
        )->isFuture();
    }

    /**
     * Delete a token record.
     */
    public function delete(CanResetPasswordContract $user): void
    {
        $this->cache->forget($this->cacheKey($user));
    }

    /**
     * Delete expired tokens.
     */
    public function deleteExpired(): void
    {
        // Cache entries expire automatically via TTL.
    }

    /**
     * Determine the cache key for the given user.
     */
    public function cacheKey(CanResetPasswordContract $user): string
    {
        return hash('sha256', $user->getEmailForPasswordReset());
    }
}
