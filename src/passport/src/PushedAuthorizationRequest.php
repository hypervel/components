<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Carbon\CarbonInterface;
use Hypervel\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $client_id
 * @property array $parameters
 * @property array $scopes
 * @property string $redirect_uri
 * @property CarbonInterface $expires_at
 */
class PushedAuthorizationRequest extends Model
{
    /**
     * The database table used by the model.
     */
    // @phpstan-ignore hypervel.legacyModelProperty (a subclass's own #[Table] would drop this table name)
    protected ?string $table = 'oauth_pushed_authorization_requests';

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
        'scopes' => 'array',
        'parameters' => 'array',
        'expires_at' => 'datetime',
    ];

    /**
     * Indicates if the model should be timestamped.
     */
    // @phpstan-ignore hypervel.legacyModelProperty (#[WithoutTimestamps] would override a subclass's $timestamps = true)
    public bool $timestamps = false;

    /**
     * The "type" of the primary key ID.
     */
    // @phpstan-ignore hypervel.legacyModelProperty (#[Table] would override a subclass's $keyType = 'int')
    protected string $keyType = 'string';

    /**
     * Get the current connection name for the model.
     */
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? config('passport.connection');
    }
}
