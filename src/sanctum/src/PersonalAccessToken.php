<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Carbon\CarbonInterface;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Sanctum\Contracts\HasAbilities;
use UnitEnum;

use function Hypervel\Support\enum_value;
use function Hypervel\Support\now;

/**
 * @property int|string $id
 * @property array $abilities
 * @property string $token
 * @property string $name
 * @property \Hypervel\Database\Eloquent\Model $tokenable
 * @property ?CarbonInterface $last_used_at
 * @property ?CarbonInterface $expires_at
 * @method static \Hypervel\Database\Eloquent\Builder where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
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

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function (PersonalAccessToken $model): void {
            if (! config('sanctum.cache.enabled')) {
                return;
            }

            // Eloquent fires updating before adding updated_at, so this exact
            // dirty set identifies Sanctum's internal audit write.
            if (array_keys($model->getDirty()) === ['last_used_at']) {
                self::forgetTokenEntry(self::getCache(), $model->id);

                return;
            }

            self::clearTokenCache($model->id);
        });

        static::deleting(function (PersonalAccessToken $model): void {
            if (config('sanctum.cache.enabled')) {
                self::clearTokenCache($model->id);
            }
        });
    }

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
        if (strpos($token, '|') === false) {
            // Hypervel only supports the id|token format created by createToken().
            // Laravel's legacy plain-token lookup is intentionally omitted because
            // Sanctum's cache and invalidation paths are keyed by token ID.
            return null;
        }

        [$id, $plainToken] = explode('|', $token, 2);

        if (! static::isValidTokenIdentifier($id)) {
            return null;
        }

        $accessToken = config('sanctum.cache.enabled')
            ? self::findTokenUsingCache($id)
            : static::find($id);

        if (! $accessToken) {
            return null;
        }

        if (! hash_equals($accessToken->token, hash('sha256', $plainToken))) {
            return null;
        }

        return $accessToken;
    }

    /**
     * Find token using cache.
     */
    protected static function findTokenUsingCache(string $id): ?static
    {
        $cache = self::getCache();

        return $cache->rememberNullable(
            self::getCacheKey($id),
            config('sanctum.cache.ttl'),
            fn () => static::find($id)?->unsetRelation('tokenable')
        );
    }

    /**
     * Find the tokenable model for a token with caching support.
     */
    public static function findTokenable(PersonalAccessToken $accessToken): ?Authenticatable
    {
        if ($accessToken->relationLoaded('tokenable')) {
            return $accessToken->getRelation('tokenable');
        }

        if (! config('sanctum.cache.enabled')) {
            return $accessToken->getAttribute('tokenable');
        }

        $cache = self::getCache();
        $cacheKey = self::getCacheKey($accessToken->id) . ':tokenable';

        /** @var ?Authenticatable $tokenable */
        $tokenable = $cache->rememberNullable(
            $cacheKey,
            config('sanctum.cache.ttl'),
            fn () => $accessToken->getAttribute('tokenable')
        );
        $accessToken->setRelation('tokenable', $tokenable);

        return $tokenable;
    }

    /**
     * Determine if the token identifier can be queried by this model.
     */
    protected static function isValidTokenIdentifier(string $id): bool
    {
        if ($id === '') {
            return false;
        }

        return (new static)->getKeyType() !== 'int' || ctype_digit($id);
    }

    /**
     * Determine if the token has a given ability.
     */
    public function can(UnitEnum|string $ability): bool
    {
        $ability = enum_value($ability);

        return in_array('*', $this->abilities)
               || array_key_exists($ability, array_flip($this->abilities));
    }

    /**
     * Determine if the token is missing a given ability.
     */
    public function cant(UnitEnum|string $ability): bool
    {
        return ! $this->can($ability);
    }

    /**
     * Clear token cache.
     */
    public static function clearTokenCache(int|string $tokenId): void
    {
        $cache = self::getCache();
        self::forgetTokenEntry($cache, $tokenId);
        $cache->forget(self::getCacheKey($tokenId) . ':tokenable');
    }

    /**
     * Forget the cached personal access token entry.
     */
    protected static function forgetTokenEntry(CacheRepository $cache, int|string $tokenId): void
    {
        $cache->forget(self::getCacheKey($tokenId));
    }

    /**
     * Store the time the token was last used.
     */
    public function updateLastUsedAt(): void
    {
        $now = now();
        $cacheEnabled = (bool) config('sanctum.cache.enabled');

        if (
            $cacheEnabled
            && $this->last_used_at !== null
            && $this->last_used_at->diffInSeconds($now)
                < config('sanctum.cache.last_used_at_update_interval')
        ) {
            return;
        }

        $connection = $this->getConnection();
        // A successful internal audit write should not change the application's sticky read routing.
        $hasModifiedRecords = $connection->hasModifiedRecords();
        $previousLastUsedAt = $this->getRawOriginal('last_used_at');

        $saved = $this->forceFill(['last_used_at' => $now])->save();

        if (! $saved) {
            $this->setAttribute('last_used_at', $previousLastUsedAt);

            return;
        }

        $connection->setRecordModificationState($hasModifiedRecords);

        if ($cacheEnabled) {
            static::getCache()->put(
                static::getCacheKey($this->id),
                $this->withoutRelation('tokenable'),
                config('sanctum.cache.ttl'),
            );
        }
    }

    /**
     * Get cache instance.
     */
    protected static function getCache(): CacheRepository
    {
        $cacheManager = Container::getInstance()->make('cache');
        $store = config('sanctum.cache.store');

        return $store !== null && $store !== ''
            ? $cacheManager->store($store)
            : $cacheManager->store();
    }

    /**
     * Get cache key for token and tokenable.
     */
    protected static function getCacheKey(int|string $tokenId): string
    {
        $prefix = config('sanctum.cache.prefix');
        return "{$prefix}:{$tokenId}";
    }
}
