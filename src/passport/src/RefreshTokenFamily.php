<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Carbon\CarbonInterface;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property null|CarbonInterface $revoked_at
 * @property null|CarbonInterface $created_at
 * @property null|CarbonInterface $updated_at
 */
class RefreshTokenFamily extends Model
{
    use HasUuids;

    /**
     * The database table used by the model.
     */
    protected ?string $table = 'oauth_refresh_token_families';

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
    protected array $casts = ['revoked_at' => 'datetime'];

    /**
     * Get the refresh tokens that belong to the family.
     *
     * @return HasMany<RefreshToken, $this>
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(Passport::refreshTokenModel(), 'family_id');
    }

    /**
     * Get the current connection name for the model.
     */
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? config('passport.connection');
    }
}
