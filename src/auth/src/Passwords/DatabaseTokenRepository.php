<?php

declare(strict_types=1);

namespace Hypervel\Auth\Passwords;

use Hypervel\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Hypervel\Contracts\Hashing\Hasher as HasherContract;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Support\Carbon;
use Hypervel\Support\Str;
use SensitiveParameter;

class DatabaseTokenRepository implements TokenRepositoryInterface
{
    /**
     * Create a new token repository instance.
     *
     * @param int $expires the number of seconds a token should remain valid
     * @param int $throttle minimum number of seconds before the user can generate new password reset tokens
     */
    public function __construct(
        protected ConnectionInterface $connection,
        protected HasherContract $hasher,
        protected string $table,
        protected string $hashKey,
        protected int $expires = 3600,
        protected int $throttle = 60,
    ) {
    }

    /**
     * Create a new token record.
     */
    public function create(CanResetPasswordContract $user): string
    {
        $email = $user->getEmailForPasswordReset();

        $this->deleteExisting($user);

        // We will create a new, random token for the user so that we can e-mail them
        // a safe link to the password reset form. Then we will insert a record in
        // the database so that we can verify the token within the actual reset.
        $token = $this->createNewToken();

        $this->getTable()->insert($this->getPayload($email, $token));

        return $token;
    }

    /**
     * Delete all existing reset tokens from the database.
     */
    protected function deleteExisting(CanResetPasswordContract $user): int
    {
        return $this->getTable()->where('email', $user->getEmailForPasswordReset())->delete();
    }

    /**
     * Build the record payload for the table.
     */
    protected function getPayload(string $email, #[SensitiveParameter] string $token): array
    {
        return ['email' => $email, 'token' => $this->hasher->make($token), 'created_at' => new Carbon()];
    }

    /**
     * Determine if a token record exists and is valid.
     */
    public function exists(CanResetPasswordContract $user, #[SensitiveParameter] string $token): bool
    {
        $record = (array) $this->getTable()->where(
            'email',
            $user->getEmailForPasswordReset()
        )->first();

        return $record
               && ! $this->tokenExpired($record['created_at'])
               && $this->hasher->check($token, $record['token']);
    }

    /**
     * Determine if the token has expired.
     */
    protected function tokenExpired(string $createdAt): bool
    {
        return Carbon::parse($createdAt)->addSeconds($this->expires)->isPast();
    }

    /**
     * Determine if the given user recently created a password reset token.
     */
    public function recentlyCreatedToken(CanResetPasswordContract $user): bool
    {
        $record = (array) $this->getTable()->where(
            'email',
            $user->getEmailForPasswordReset()
        )->first();

        return $record && $this->tokenRecentlyCreated($record['created_at']);
    }

    /**
     * Determine if the token was recently created.
     */
    protected function tokenRecentlyCreated(string $createdAt): bool
    {
        if ($this->throttle <= 0) {
            return false;
        }

        return Carbon::parse($createdAt)->addSeconds(
            $this->throttle
        )->isFuture();
    }

    /**
     * Delete a token record by user.
     */
    public function delete(CanResetPasswordContract $user): void
    {
        $this->deleteExisting($user);
    }

    /**
     * Delete expired tokens.
     */
    public function deleteExpired(): void
    {
        $expiredAt = Carbon::now()->subSeconds($this->expires);

        $this->getTable()->where('created_at', '<', $expiredAt)->delete();
    }

    /**
     * Create a new token for the user.
     */
    public function createNewToken(): string
    {
        return hash_hmac('sha256', Str::random(40), $this->hashKey);
    }

    /**
     * Get the database connection instance.
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Begin a new database query against the table.
     */
    protected function getTable(): Builder
    {
        return $this->connection->table($this->table);
    }

    /**
     * Get the hasher instance.
     */
    public function getHasher(): HasherContract
    {
        return $this->hasher;
    }
}
