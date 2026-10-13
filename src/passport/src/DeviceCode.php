<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Carbon\CarbonInterface;
use Hypervel\Database\Eloquent\Model;

/**
 * @property string $id
 * @property null|int|string $user_id
 * @property null|string $provider
 * @property string $client_id
 * @property string $user_code
 * @property array $scopes
 * @property bool $revoked
 * @property null|CarbonInterface $user_approved_at
 * @property null|CarbonInterface $last_polled_at
 * @property null|CarbonInterface $expires_at
 */
class DeviceCode extends Model
{
    /**
     * The database table used by the model.
     */
    protected ?string $table = 'oauth_device_codes';

    /**
     * The "type" of the primary key ID.
     */
    protected string $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public bool $incrementing = false;

    /**
     * Indicates if the model should be timestamped.
     */
    public bool $timestamps = false;

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
        'user_approved_at' => 'datetime',
        'last_polled_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the current connection name for the model.
     */
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? config('passport.connection');
    }
}
