<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Carbon\CarbonInterface;
use Hypervel\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int|string $user_id
 * @property null|string $provider
 * @property string $client_id
 * @property null|string $consent_id
 * @property null|string $access_token_id
 * @property null|string $family_id
 * @property null|array $scopes
 * @property null|array<string, list<string>> $resources
 * @property null|string $dpop_jkt
 * @property bool $revoked
 * @property null|CarbonInterface $expires_at
 */
class AuthCode extends Model
{
    /**
     * The database table used by the model.
     */
    protected ?string $table = 'oauth_auth_codes';

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
        'resources' => 'array',
        'revoked' => 'bool',
        'expires_at' => 'datetime',
    ];

    /**
     * Indicates if the model should be timestamped.
     */
    public bool $timestamps = false;

    /**
     * The "type" of the primary key ID.
     */
    protected string $keyType = 'string';

    // The deprecated client() relation is omitted; client_id identifies the issuing client.

    /**
     * Get the current connection name for the model.
     */
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? config('passport.connection');
    }
}
