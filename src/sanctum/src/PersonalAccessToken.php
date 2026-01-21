<?php

declare(strict_types=1);

namespace Hypervel\Sanctum;

use BackedEnum;
use Hypervel\Database\Eloquent\Events\Deleting;
use Hypervel\Database\Eloquent\Events\Updating;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Contracts\Repository as CacheRepository;
use Hypervel\Context\ApplicationContext;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Sanctum\Contracts\HasAbilities;
use Hypervel\Support\Str;

/**
 * @property int|string $id
 * @property array $abilities
 * @property string $token
 * @property string $name
 * @property null|\Carbon\Carbon $last_used_at
 * @property null|\Carbon\Carbon $expires_at
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

    /**
     * Handle the updating event.
     */
    public function updating(Updating $event): void
    {
        if (config('sanctum.cache.enabled')) {
            self::clearTokenCache($this->id);
        }
    }

    /**
     * Handle the deleting event.
     */
    public function deleting(Deleting $event): void
    {
        if (config('sanctum.cache.enabled')) {
            self::clearTokenCache($this->id);
        }
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
            return null;
        }

        [$id, $plainToken] = explode('|', $token, 2);

        $accessToken = config('sanctum.cache.enabled')
            ? self::findTokenUsingCache($id)
            : static::find($id);

        if (! $accessToken) {
            return null;
        }

        if (! hash_equals($accessToken->token, hash('sha256', $plainToken))) {
            return null;
        }

        self::updateLastUsedAt($accessToken);

        return $accessToken;
    }

    /**
     * Find token using cache.
     */
    protected static function findTokenUsingCache(string $id): ?static
    {
        $cache = self::getCache();

        return $cache->remember(
            self::getCacheKey($id),
            config('sanctum.cache.ttl'),
            fn () => static::find($id)
        );
    }

    /**
     * Find the tokenable model for a token with caching support.
     */
    public static function findTokenable(PersonalAccessToken $accessToken): ?Authenticatable
    {
        if (! config('sanctum.cache.enabled')) {
            return $accessToken->getAttribute('tokenable');
        }

        $cache = self::getCache();
        $cacheKey = self::getCacheKey($accessToken->id) . ':tokenable';

        return $cache->remember(
            $cacheKey,
            config('sanctum.cache.ttl'),
            fn () => $accessToken->getAttribute('tokenable')
        );
    }

    /**
     * Determine if the token has a given ability.
     */
    public function can(BackedEnum|string $ability): bool
    {
        $ability = Str::from($ability);

        return in_array('*', $this->abilities)
               || array_key_exists($ability, array_flip($this->abilities));
    }

    /**
     * Determine if the token is missing a given ability.
     */
    public function cant(BackedEnum|string $ability): bool
    {
        return ! $this->can($ability);
    }

    /**
     * Clear token cache.
     */
    public static function clearTokenCache(int|string $tokenId): void
    {
        $cache = self::getCache();
        $cache->forget(self::getCacheKey($tokenId));
        $cache->forget(self::getCacheKey($tokenId) . ':tokenable');
    }

    /**
     * Update last_used_at.
     */
    protected static function updateLastUsedAt(self $token): void
    {
        // Caching disabled - update immediately
        if (! config('sanctum.cache.enabled')) {
            $token->forceFill(['last_used_at' => now()])->save();
            return;
        }

        // Caching enabled - use throttling
        $updateInterval = config('sanctum.cache.last_used_at_update_interval');
        $shouldUpdate = false;

        if (! $token->last_used_at) {
            $shouldUpdate = true;
        } else {
            $secondsSinceLastUpdate = $token->last_used_at->diffInSeconds(now());
            $shouldUpdate = $secondsSinceLastUpdate >= $updateInterval;
        }

        if ($shouldUpdate) {
            // Update last_used_at in database
            $token->forceFill(['last_used_at' => now()])->save();

            // Update cache
            $cache = self::getCache();
            $cache->put(
                self::getCacheKey($token->id),
                $token,
                config('sanctum.cache.ttl')
            );
        }
    }

    /**
     * Get cache instance.
     */
    protected static function getCache(): CacheRepository
    {
        $cacheManager = ApplicationContext::getContainer()->get(CacheManager::class);
        $store = config('sanctum.cache.store');
        return $store ? $cacheManager->store($store) : $cacheManager->store();
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
