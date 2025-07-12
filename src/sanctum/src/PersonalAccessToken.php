<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\MorphTo;
use Hypervel\Sanctum\Contracts\HasAbilities;

/**
 * @property array $abilities
 * @property string $token
 * @property string $name
 * @property null|\Carbon\Carbon $last_used_at
 * @property null|\Carbon\Carbon $expires_at
 * @method static \Hyperf\Database\Model\Builder where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method static static|null find(mixed $id, array $columns = ['*'])
 */
class PersonalAccessToken extends Model implements HasAbilities
{
    protected ?string $table = 'personal_access_tokens';

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected array $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected array $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected array $hidden = [
        'token',
    ];

    /**
     * Get the tokenable model that the access token belongs to.
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable');
    }

    /**
     * Find the token instance matching the given token.
     */
    public static function findToken(string $token): ?static
    {
        $accessToken = null;

        if (strpos($token, '|') === false) {
            /** @var static|null $accessToken */
            $accessToken = static::where('token', hash('sha256', $token))->first();
        } else {
            [$id, $token] = explode('|', $token, 2);

            if ($instance = static::find($id)) {
                $accessToken = hash_equals($instance->token, hash('sha256', $token)) ? $instance : null;
            }
        }

        if ($accessToken) {
            $accessToken->forceFill(['last_used_at' => now()])->save();
        }

        return $accessToken;
    }

    /**
     * Determine if the token has a given ability.
     */
    public function can(string $ability): bool
    {
        return in_array('*', $this->abilities)
               || array_key_exists($ability, array_flip($this->abilities));
    }

    /**
     * Determine if the token is missing a given ability.
     */
    public function cant(string $ability): bool
    {
        return ! $this->can($ability);
    }
}
