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
 * @property null|string $consent_id
 * @property null|array<string, list<string>> $resources
 * @property null|string $dpop_jkt
 * @property bool $revoked
 * @property null|CarbonInterface $expires_at
 */
class RefreshToken extends Model
{
    /**
     * The database table used by the model.
     */
    // @phpstan-ignore hypervel.legacyModelProperty (a subclass's own #[Table] would drop this table name)
    protected ?string $table = 'oauth_refresh_tokens';

    /**
     * The "type" of the primary key ID.
     */
    // @phpstan-ignore hypervel.legacyModelProperty (#[Table] would override a subclass's $keyType = 'int')
    protected string $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    // @phpstan-ignore hypervel.legacyModelProperty (#[WithoutIncrementing] would override a subclass's $incrementing = true)
    public bool $incrementing = false;

    /**
     * The guarded attributes on the model.
     *
     * @var array<string>
     */
    // @phpstan-ignore hypervel.legacyModelProperty (#[Unguarded] would replace a $guarded = ['*'] a subclass constructor sets before parent::__construct())
    protected array $guarded = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected array $casts = [
        'resources' => 'array',
        'revoked' => 'bool',
        'expires_at' => 'datetime',
    ];

    /**
     * Indicates if the model should be timestamped.
     */
    // @phpstan-ignore hypervel.legacyModelProperty (#[WithoutTimestamps] would override a subclass's $timestamps = true)
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
