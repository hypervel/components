<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Carbon\CarbonInterface;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Passport\Database\Factories\ConsentFactory;
use Hypervel\Support\Facades\Date;

/**
 * @property string $id
 * @property string $client_id
 * @property string $provider
 * @property int|string $user_id
 * @property null|array<string> $scopes
 * @property array<string, array> $permissions
 * @property null|CarbonInterface $remembered_until
 * @property null|CarbonInterface $created_at
 * @property null|CarbonInterface $updated_at
 */
class Consent extends Model
{
    /** @use HasFactory<ConsentFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The database table used by the model.
     */
    protected ?string $table = 'oauth_consents';

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
        'permissions' => 'array',
        'remembered_until' => 'datetime',
    ];

    /**
     * Get the client that received the authorization.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        // Client models may use a different connection from credentials.
        $client = Passport::client();

        return $this->newBelongsTo($client->newQuery(), $this, 'client_id', $client->getKeyName(), 'client');
    }

    /**
     * Determine whether the approval may be reused.
     */
    public function isRemembered(): bool
    {
        return $this->remembered_until === null || $this->remembered_until->isFuture();
    }

    /**
     * Determine whether the remembered approval covers the requested permissions.
     *
     * @param list<string> $scopes
     * @param array<string, array> $permissions
     */
    public function covers(array $scopes, array $permissions = []): bool
    {
        if (! $this->isRemembered()) {
            return false;
        }

        // Resource-only approval must not authorize the default audience, even without scopes.
        if (empty($permissions['resources']) && ($this->scopes === null || array_diff($scopes, $this->scopes) !== [])) {
            return false;
        }

        foreach ($permissions as $namespace => $requested) {
            if ($namespace === 'resources') {
                foreach ($requested as $resource => $resourceScopes) {
                    if (! isset($this->permissions['resources'][$resource])
                        || array_diff($resourceScopes, $this->permissions['resources'][$resource]) !== []) {
                        return false;
                    }
                }
            } elseif (array_diff($requested, $this->permissions[$namespace] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Forget the approval without revoking issued credentials.
     */
    public function forget(): bool
    {
        return $this->forceFill(['remembered_until' => Date::now()])->save();
    }

    /**
     * Revoke the authorization and remove its remembered approval.
     */
    public function revoke(): int|bool|null
    {
        return $this->delete();
    }

    /**
     * Delete the consent and its authorization atomically.
     */
    public function delete(): int|bool|null
    {
        if (! $this->exists) {
            return null;
        }

        return $this->getConnection()->transaction(fn () => parent::delete());
    }

    /**
     * Revoke linked credentials before deleting the approval.
     */
    protected function performDeleteOnModel(): void
    {
        $consent = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->first();

        if ($consent !== null) {
            app(ConsentRepository::class)->revokeCredentials($consent);
            parent::performDeleteOnModel();
        } else {
            $this->exists = false;
        }
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
     * @return ConsentFactory
     */
    protected static function newFactory(): Factory
    {
        return ConsentFactory::new();
    }
}
