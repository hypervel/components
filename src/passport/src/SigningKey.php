<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Carbon\CarbonInterface;
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;

/**
 * @property string $id
 * @property string $public_key
 * @property null|string $private_key
 * @property CarbonInterface $activates_at
 * @property int $max_token_lifetime
 * @property null|CarbonInterface $retain_until
 * @property null|CarbonInterface $retires_at
 * @property null|CarbonInterface $created_at
 * @property null|CarbonInterface $updated_at
 */
class SigningKey extends Model
{
    /** @use HasFactory<SigningKeyFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The database table used by the model.
     */
    protected ?string $table = 'oauth_signing_keys';

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
    protected array $hidden = ['private_key'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected array $casts = [
        'private_key' => 'encrypted',
        'activates_at' => 'immutable_datetime',
        'max_token_lifetime' => 'integer',
        'retain_until' => 'immutable_datetime',
        'retires_at' => 'immutable_datetime',
    ];

    /**
     * Get the current connection name for the model.
     */
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? config('passport.connection');
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): SigningKeyFactory
    {
        return SigningKeyFactory::new();
    }
}
