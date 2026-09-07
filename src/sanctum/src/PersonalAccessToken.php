<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use Carbon\CarbonInterface;
use Hypervel\Cache\ModelCacheCoordinator;
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
            static::invalidateTokenAfterMutation($token);
        });

        static::updated(function (self $token): void {
            static::invalidateTokenAfterMutation($token);
        });

        static::deleted(function (self $token): void {
            static::invalidateTokenAfterMutation($token);
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

        $accessToken = config()->boolean('sanctum.cache.enabled', false)
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
        return static::getCacheCoordinator()->fill(
            static::getCache(),
            static::getCacheKey($id),
            config()->integer('sanctum.cache.ttl', Sanctum::DEFAULT_CACHE_TTL),
            fn () => static::query()->useWritePdo()->find($id)?->unsetRelation('tokenable'),
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

        if (! config()->boolean('sanctum.cache.enabled', false)) {
            return $accessToken->getAttribute('tokenable');
        }

        $morphType = (string) $accessToken->getAttribute('tokenable_type');
        /** @var int|string $morphId */
        $morphId = $accessToken->getAttribute('tokenable_id');

        $tokenable = static::getCacheCoordinator()->fill(
            static::getCache(),
            static::getTokenableCacheKey($morphType, $morphId),
            config()->integer('sanctum.cache.ttl', Sanctum::DEFAULT_CACHE_TTL),
            function () use ($accessToken): ?Authenticatable {
                $tokenable = $accessToken->tokenable()->useWritePdo()->getResults();

                return $tokenable instanceof Authenticatable ? $tokenable : null;
            },
            cacheNull: false,
        );

        $tokenable = $tokenable instanceof Authenticatable ? $tokenable : null;

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
     * Clear the cached personal access token.
     */
    public static function clearTokenCache(int|string $tokenId): void
    {
        $cache = static::getCache();
        $coordinator = static::getCacheCoordinator();

        (new static)->getConnection()->afterCommitOrNow(
            fn () => $coordinator->invalidate($cache, static::getCacheKey($tokenId)),
        );
    }

    /**
     * Clear the cached tokenable model.
     */
    public static function clearTokenableCache(Model $tokenable): void
    {
        $cache = static::getCache();
        $coordinator = static::getCacheCoordinator();
        /** @var int|string $morphId */
        $morphId = $tokenable->getKey();
        $cacheKey = static::getTokenableCacheKey($tokenable->getMorphClass(), $morphId);

        $tokenable->getConnection()->afterCommitOrNow(
            fn () => $coordinator->invalidate($cache, $cacheKey),
        );
    }

    /**
     * Store the time the token was last used.
     */
    public function updateLastUsedAt(): void
    {
        $now = now();
        $cacheEnabled = config()->boolean('sanctum.cache.enabled', false);

        if (
            $cacheEnabled
            && $this->last_used_at !== null
            && $this->last_used_at->diffInSeconds($now)
                < config()->integer(
                    'sanctum.cache.last_used_at_update_interval',
                    Sanctum::DEFAULT_LAST_USED_AT_UPDATE_INTERVAL,
                )
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
    }

    /**
     * Invalidate a token entry after its model mutation settles.
     */
    protected static function invalidateTokenAfterMutation(self $token): void
    {
        if (! config()->boolean('sanctum.cache.enabled', false)) {
            return;
        }

        /** @var int|string $id */
        $id = $token->getKey();
        $cache = static::getCache();
        $coordinator = static::getCacheCoordinator();

        $token->getConnection()->afterCommitOrNow(
            fn () => $coordinator->invalidate($cache, static::getCacheKey($id)),
        );
    }

    /**
     * Get cache instance.
     */
    protected static function getCache(): CacheRepository
    {
        $cacheManager = Container::getInstance()->make('cache');
        $store = config()->get('sanctum.cache.store');

        return $store !== null && $store !== ''
            ? $cacheManager->store($store)
            : $cacheManager->store();
    }

    /**
     * Get the model cache coordinator.
     */
    protected static function getCacheCoordinator(): ModelCacheCoordinator
    {
        return Container::getInstance()->make(ModelCacheCoordinator::class);
    }

    /**
     * Get the cache key for a token.
     */
    protected static function getCacheKey(int|string $tokenId): string
    {
        $prefix = config()->string('sanctum.cache.prefix', 'sanctum');
        return "{$prefix}:{$tokenId}";
    }

    /**
     * Get the cache key for a tokenable model identity.
     */
    protected static function getTokenableCacheKey(string $morphType, int|string $morphId): string
    {
        $id = (string) $morphId;
        $identity = strlen($morphType) . ":{$morphType}|" . strlen($id) . ":{$id}";

        return static::getCacheKey('tokenable') . ':' . hash('xxh128', $identity);
    }
}
