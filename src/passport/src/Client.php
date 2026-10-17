<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Carbon\CarbonInterface;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Passport\Database\Factories\ClientFactory;
use SensitiveParameter;

/**
 * @property string $id
 * @property null|int|string $owner_id
 * @property null|string $owner_type
 * @property string $name
 * @property null|string $secret
 * @property null|string $provider
 * @property array $redirect_uris
 * @property array $grant_types
 * @property null|array $scopes
 * @property bool $revoked
 * @property null|CarbonInterface $created_at
 * @property null|CarbonInterface $updated_at
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use ResolvesInheritedScopes;
    use HasUuids;

    /**
     * The database table used by the model.
     */
    protected ?string $table = 'oauth_clients';

    /**
     * The guarded attributes on the model.
     *
     * @var array<string>
     */
    protected array $guarded = [];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array<int, string>
     */
    protected array $hidden = [
        'secret',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected array $casts = [
        'grant_types' => 'array',
        'scopes' => 'array',
        'redirect_uris' => 'array',
        'revoked' => 'bool',
    ];

    /**
     * The temporary plain-text client secret.
     *
     * This is only available during the request that created the client.
     */
    public ?string $plainSecret = null;

    /**
     * Boot the client model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::created(static function (self $client): void {
            // Missing credentials are memoized locally, never in the shared cache.
            app(PassportCache::class)->forgetClientMemo((string) $client->getKey());
        });

        static::updated(static function (self $client): void {
            app(PassportCache::class)->forgetClient((string) $client->getKey(), $client->getConnection());
        });

        static::deleted(static function (self $client): void {
            app(PassportCache::class)->forgetClient((string) $client->getKey(), $client->getConnection());
        });
    }

    // The deprecated user() relation is omitted; use owner().

    /**
     * Get the owner of the registered client.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner');
    }

    // The deprecated authCodes() relation is omitted.

    /**
     * Get the authorizations granted to the client.
     *
     * @return HasMany<Consent, $this>
     */
    public function consents(): HasMany
    {
        $consent = Passport::consent();

        return new HasMany($consent->newQuery(), $this, $consent->qualifyColumn('client_id'), $this->getKeyName());
    }

    /**
     * Get all of the tokens that belong to the client.
     *
     * @return TokenRelation<$this>
     */
    public function tokens(): TokenRelation
    {
        // Match authentication lookups even when the client uses another connection.
        $token = Passport::token();

        return new TokenRelation($token->newQuery(), $this, $token->qualifyColumn('client_id'), $this->getKeyName());
    }

    /**
     * Interact with the client's secret.
     */
    protected function secret(): Attribute
    {
        return Attribute::make(
            set: function (#[SensitiveParameter] ?string $value): ?string {
                $this->plainSecret = $value;

                // Generated secrets already resist guessing; password hashing would only add worker-blocking CPU cost.
                return $value === null ? null : hash('sha256', $value);
            },
        );
    }

    /**
     * Interact with the client's plain secret.
     */
    protected function plainSecret(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->plainSecret
        );
    }

    // Pre-13 redirect and grant-type accessors are omitted; both columns use array casts.

    /**
     * Determine if the client is a "first party" client.
     */
    public function firstParty(): bool
    {
        return $this->owner_id === null;
    }

    /**
     * Determine if the client should skip the authorization prompt.
     *
     * @param Scope[] $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return false;
    }

    /**
     * Determine if the client has the given grant type.
     */
    public function hasGrantType(string $grantType): bool
    {
        return in_array($grantType, $this->grant_types, true);
    }

    /**
     * Determine whether the client has the given scope.
     */
    public function hasScope(string $scope): bool
    {
        return ! isset($this->attributes['scopes']) || $this->scopeExistsIn($scope, $this->scopes);
    }

    /**
     * Determine if the client is a confidential client.
     */
    public function confidential(): bool
    {
        return ! empty($this->getAttributes()['secret'] ?? null);
    }

    /**
     * Determine whether the supplied client secret matches the stored digest.
     */
    public function secretMatches(#[SensitiveParameter] string $secret): bool
    {
        return $this->secret !== null && hash_equals($this->secret, hash('sha256', $secret));
    }

    /**
     * Revoke the client and its ability to authenticate tokens.
     */
    public function revoke(): bool
    {
        return $this->forceFill(['revoked' => true])->save();
    }

    /**
     * Get the current connection name for the model.
     */
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? config('passport.connection');
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return ClientFactory
     */
    protected static function newFactory(): Factory
    {
        return ClientFactory::new();
    }
}
