<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Carbon\CarbonInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Passport\Events\AccessTokenRevoked;
use Hypervel\Support\Facades\Event;

/**
 * @property string $id
 * @property null|int|string $user_id
 * @property null|string $provider
 * @property string $client_id
 * @property null|string $consent_id
 * @property null|string $name
 * @property null|array $scopes
 * @property bool $revoked
 * @property null|CarbonInterface $created_at
 * @property null|CarbonInterface $updated_at
 * @property null|CarbonInterface $expires_at
 */
class Token extends Model
{
    use ResolvesInheritedScopes;

    /**
     * The database table used by the model.
     */
    protected ?string $table = 'oauth_access_tokens';

    /**
     * The "type" of the primary key ID.
     */
    protected string $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public bool $incrementing = false;

    /**
     * The guarded attributes on the model.
     *
     * @var array<string>
     */
    protected array $guarded = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected array $casts = [
        'scopes' => 'array',
        'revoked' => 'bool',
        'expires_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::created(static function (self $token): void {
            // Missing credentials are memoized locally, never in the shared cache.
            app(PassportCache::class)->forgetTokenMemo((string) $token->getKey());
        });

        static::updated(static function (self $token): void {
            $tokenId = (string) $token->getKey();
            $connection = $token->getConnection();
            app(PassportCache::class)->forgetToken($tokenId, $connection);

            if ($token->wasChanged('revoked') && $token->revoked) {
                $connection->afterCommitOrNow(static function () use ($tokenId): void {
                    if (Event::hasListeners(AccessTokenRevoked::class)) {
                        Event::dispatch(new AccessTokenRevoked($tokenId));
                    }
                });
            }
        });

        static::deleted(static function (self $token): void {
            app(PassportCache::class)->forgetToken((string) $token->getKey(), $token->getConnection());
        });
    }

    /**
     * Get the client that the token belongs to.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        // Match client authentication even when credentials use another connection.
        $client = Passport::client();

        return $this->newBelongsTo(
            $client->newQuery(),
            $this,
            'client_id',
            $client->getKeyName(),
            'client'
        );
    }

    /**
     * Get the refresh token associated with the token.
     *
     * @return HasOne<RefreshToken, $this>
     */
    public function refreshToken(): HasOne
    {
        return $this->hasOne(Passport::refreshTokenModel(), 'access_token_id');
    }

    // The deprecated user() relation is omitted; the guard resolves the recorded provider.

    /**
     * Determine if the token has a given scope.
     */
    public function can(string $scope): bool
    {
        if (empty($this->scopes)) {
            return false;
        }

        return in_array('*', $this->scopes, true)
            || $this->scopeExistsIn($scope, $this->scopes);
    }

    /**
     * Determine if the token is missing a given scope.
     */
    public function cant(string $scope): bool
    {
        return ! $this->can($scope);
    }

    /**
     * Revoke the token instance.
     */
    public function revoke(): bool
    {
        return $this->forceFill(['revoked' => true])->save();
    }

    /**
     * Find an access token for authentication.
     */
    public static function findToken(string $id): ?static
    {
        return app(PassportCache::class)->findToken(static::class, $id);
    }

    /**
     * Clear the cached access token after a query mutation.
     */
    public static function clearTokenCache(string $id): void
    {
        app(PassportCache::class)->forgetToken($id, (new static)->getConnection());
    }

    /**
     * Get the current connection name for the model.
     */
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? config('passport.connection');
    }
}
