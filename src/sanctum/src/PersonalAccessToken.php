<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Carbon\CarbonInterface;
use Closure;
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

        static::created(function (self $token): void {
            if (! config('sanctum.cache.enabled')) {
                return;
            }

            /** @var int|string $id */
            $id = $token->getKey();

            $token->settleCacheMutation(
                fn () => static::forgetTokenEntry(static::getCache(), $id)
            );
        });

        static::updated(function (self $token): void {
            if (! config('sanctum.cache.enabled')) {
                return;
            }

            /** @var int|string $id */
            $id = $token->getKey();
            $lastUsedAtOnly = $token->wasOnlyLastUsedAtChanged();

            $token->settleCacheMutation(function () use ($id, $lastUsedAtOnly): void {
                if ($lastUsedAtOnly) {
                    static::forgetTokenEntry(static::getCache(), $id);

                    return;
                }

                static::clearTokenCache($id);
            });
        });

        static::deleted(function (self $token): void {
            if (! config('sanctum.cache.enabled')) {
                return;
            }

            /** @var int|string $id */
            $id = $token->getKey();

            $token->settleCacheMutation(fn () => static::clearTokenCache($id));
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
        if (! str_contains($token, '|')) {
            // Hypervel only supports the id|token format created by createToken().
            // Laravel's legacy plain-token lookup is intentionally omitted because
            // Sanctum's cache and invalidation paths are keyed by token ID.
            return null;
        }

        [$id, $plainToken] = explode('|', $token, 2);

        if ($id === '' || $plainToken === '') {
            return null;
        }

        if ((new static)->getKeyType() === 'int'
            && (! ctype_digit($id) || filter_var($id, FILTER_VALIDATE_INT) === false)) {
            return null;
        }

        $accessToken = config('sanctum.cache.enabled')
            ? static::findTokenUsingCache($id)
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
        $cache = static::getCache();

        return $cache->rememberNullable(
            static::getCacheKey($id),
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

        $cache = static::getCache();
        /** @var int|string $id */
        $id = $accessToken->getKey();
        $cacheKey = static::getCacheKey($id) . ':tokenable';

        // A scoped miss may be visible in another query context, so cache only positive tokenables.
        $tokenable = $cache->get($cacheKey);

        if (! $tokenable instanceof Authenticatable) {
            $tokenable = $accessToken->getAttribute('tokenable');

            if ($tokenable instanceof Authenticatable) {
                $cache->put($cacheKey, $tokenable, config('sanctum.cache.ttl'));
            } else {
                $tokenable = null;
            }
        }

        $accessToken->setRelation('tokenable', $tokenable);

        return $tokenable;
    }

    /**
     * Determine if the token has a given ability.
     */
    public function can(UnitEnum|string $ability): bool
    {
        $ability = enum_value($ability);

        return in_array('*', $this->abilities, true)
               || in_array($ability, $this->abilities, true);
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
        $cache = static::getCache();
        static::forgetTokenEntry($cache, $tokenId);
        $cache->forget(static::getCacheKey($tokenId) . ':tokenable');
    }

    /**
     * Forget the cached personal access token entry.
     */
    protected static function forgetTokenEntry(CacheRepository $cache, int|string $tokenId): void
    {
        $cache->forget(static::getCacheKey($tokenId));
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
            /** @var int|string $id */
            $id = $this->getKey();
            $snapshot = $this->withoutRelation('tokenable');
            $ttl = config('sanctum.cache.ttl');

            $this->settleCacheMutation(
                fn () => static::getCache()->put(static::getCacheKey($id), $snapshot, $ttl)
            );
        }
    }

    /**
     * Determine whether only the last-used timestamp changed.
     */
    protected function wasOnlyLastUsedAtChanged(): bool
    {
        $changes = $this->getChanges();

        if (($updatedAt = $this->getUpdatedAtColumn()) !== null) {
            unset($changes[$updatedAt]);
        }

        return array_keys($changes) === ['last_used_at'];
    }

    /**
     * Run a cache mutation after its database transaction settles.
     */
    protected function settleCacheMutation(Closure $callback): void
    {
        $connection = $this->getConnection();

        if ($connection->getTransactionManager() === null && $connection->transactionLevel() === 0) {
            $callback();

            return;
        }

        $connection->afterCommit($callback);
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
