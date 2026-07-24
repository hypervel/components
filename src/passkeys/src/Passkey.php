<?php

declare(strict_types=1);

namespace Hypervel\Passkeys;

use Carbon\CarbonInterface;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Passkeys\Contracts\PasskeyUser;
use Hypervel\Passkeys\Support\Aaguids;

/**
 * @mixin Builder<Passkey>
 *
 * @property int $id
 * @property string $user_type
 * @property int|string $user_id
 * @property string $name
 * @property string $credential_id
 * @property array<string, mixed> $credential
 * @property ?CarbonInterface $last_used_at
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 * @property-read PasskeyUser $user
 * @property-read null|string $authenticator
 */
class Passkey extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected array $fillable = [
        'name',
        'credential_id',
        'credential',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected array $appends = [
        'authenticator',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credential' => 'json',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the passkey.
     *
     * @return MorphTo<Model, $this>
     */
    public function user(): MorphTo
    {
        return $this->morphTo('user');
    }

    /**
     * Get the authenticator name based on the AAGUID.
     */
    protected function authenticator(): Attribute
    {
        return Attribute::get(function (): ?string {
            $aaguid = $this->credential['aaguid'] ?? null;

            if (! is_string($aaguid) || $aaguid === Aaguids::unknown()) {
                return null;
            }

            return Aaguids::labelFor($aaguid);
        });
    }
}
