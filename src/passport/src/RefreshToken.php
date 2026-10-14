<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Carbon\CarbonInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $access_token_id
 * @property null|string $provider
 * @property string $family_id
 * @property bool $revoked
 * @property null|CarbonInterface $expires_at
 */
class RefreshToken extends Model
{
    /**
     * The database table used by the model.
     */
    protected ?string $table = 'oauth_refresh_tokens';

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
        'revoked' => 'bool',
        'expires_at' => 'datetime',
    ];

    /**
     * Indicates if the model should be timestamped.
     */
    public bool $timestamps = false;

    /**
     * Get the access token that the refresh token belongs to.
     *
     * @return BelongsTo<Token, $this>
     */
    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(Passport::tokenModel());
    }

    /**
     * Revoke the token instance.
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
}
