<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Drivers;

use Hypervel\Contracts\Cache\Repository;
use Hypervel\Database\ConnectionName;
use Hypervel\Pennant\FeaturePartition;
use Hypervel\Support\Str;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Shares a database store's stored values between servers through a cache store.
 *
 * Each cached value records the partition and scope tokens that were current when it was
 * read. Writes replace those tokens instead of deleting values, so a value read before a
 * write but cached after it no longer matches and is ignored. The database stays
 * authoritative, and an evicted value is read again rather than resolved again.
 */
class StoredValueCache
{
    /**
     * The default number of seconds values and tokens are cached for.
     */
    public const int DEFAULT_TTL = 3600;

    /**
     * Create a new stored value cache instance.
     *
     * @param list<array{Repository, int}> $targets the caches and TTLs whose tokens are replaced on writes
     * @param null|Repository $cache the cache values are read from and published to
     */
    public function __construct(
        protected string $table,
        protected array $targets,
        protected ?Repository $cache = null,
        protected int $ttl = self::DEFAULT_TTL,
    ) {
    }

    /**
     * Determine if values are read from and published to the cache.
     */
    public function storesValues(): bool
    {
        return $this->cache !== null;
    }

    /**
     * Read the cached values of the given features and scopes.
     *
     * Missing tokens are created before the database is read, so a write that commits
     * afterwards replaces them. Without tokens, nothing read now may be published.
     *
     * @param array<string, array<array-key, mixed>> $pairs scopes keyed by their serialized identifiers
     * @return array{array<string, array<array-key, mixed>>, null|array{p: string, s: array<array-key, string>}} the cached values, and the tokens to publish the database values under
     */
    public function read(string $connection, ?FeaturePartition $partition, array $pairs): array
    {
        $prefix = $this->prefix($connection, $partition);
        $partitionKey = $this->partitionTokenKey($prefix);
        $scopeKeys = [];
        $valueKeys = [];
        $keys = [$partitionKey];

        foreach ($pairs as $feature => $scopes) {
            foreach ($scopes as $scope => $unused) {
                if (! isset($scopeKeys[$scope])) {
                    $keys[] = $scopeKeys[$scope] = $this->scopeTokenKey($prefix, $scope);
                }

                $keys[] = $valueKeys[$feature][$scope] = $this->valueKey($prefix, $feature, $scope);
            }
        }

        $entries = $this->cache->get($keys);
        $tokens = ['p' => $entries[$partitionKey], 's' => []];
        $missing = [];

        if ($tokens['p'] === null) {
            $missing[$partitionKey] = $tokens['p'] = (string) Str::ulid();
        }

        foreach ($scopeKeys as $scope => $key) {
            $tokens['s'][$scope] = $entries[$key];

            if ($tokens['s'][$scope] === null) {
                $missing[$key] = $tokens['s'][$scope] = (string) Str::ulid();
            }
        }

        $values = [];

        foreach ($valueKeys as $feature => $scopes) {
            foreach ($scopes as $scope => $key) {
                $entry = $entries[$key];

                if (is_array($entry) && $entry['p'] === $tokens['p'] && $entry['s'] === $tokens['s'][$scope]) {
                    $values[$feature][$scope] = json_decode($entry['v'], flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);
                }
            }
        }

        if ($missing !== [] && ! $this->cache->put($missing, $this->ttl)) {
            return [$values, null];
        }

        return [$values, $tokens];
    }

    /**
     * Publish the stored JSON values of the given features and scopes under the tokens they were read with.
     *
     * @param array{p: string, s: array<array-key, string>} $tokens
     * @param array<string, array<array-key, string>> $stored
     */
    public function publish(string $connection, ?FeaturePartition $partition, array $tokens, array $stored): void
    {
        $prefix = $this->prefix($connection, $partition);
        $entries = [];

        foreach ($stored as $feature => $scopes) {
            foreach ($scopes as $scope => $json) {
                $entries[$this->valueKey($prefix, $feature, $scope)] = ['p' => $tokens['p'], 's' => $tokens['s'][$scope], 'v' => $json];
            }
        }

        $this->cache->put($entries, $this->ttl);
    }

    /**
     * Invalidate the cached values of the given scopes.
     *
     * @param list<string> $scopes
     */
    public function rotateScopes(string $connection, ?FeaturePartition $partition, array $scopes): void
    {
        $prefix = $this->prefix($connection, $partition);
        $tokens = [];

        foreach ($scopes as $scope) {
            $tokens[$this->scopeTokenKey($prefix, $scope)] = (string) Str::ulid();
        }

        $this->rotate($tokens);
    }

    /**
     * Invalidate every cached value in the partition.
     */
    public function rotatePartition(string $connection, ?FeaturePartition $partition): void
    {
        $this->rotate([$this->partitionTokenKey($this->prefix($connection, $partition)) => (string) Str::ulid()]);
    }

    /**
     * Replace the given tokens in every target cache.
     *
     * Every target is attempted, since a failure in one doesn't make the others stale.
     *
     * @param array<string, string> $tokens
     *
     * @throws RuntimeException when a target fails to store the tokens
     */
    protected function rotate(array $tokens): void
    {
        $exception = null;

        foreach ($this->targets as [$cache, $ttl]) {
            try {
                if (! $cache->put($tokens, $ttl)) {
                    $exception ??= new RuntimeException('Unable to invalidate cached feature values. They may be stale until they expire.');
                }
            } catch (CanceledException $canceledException) {
                throw $canceledException;
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Get the key prefix of the given connection and partition.
     *
     * Stores reading the same rows share entries, so the prefix names the storage, not the store.
     */
    protected function prefix(string $connection, ?FeaturePartition $partition): string
    {
        return 'pennant:'
            . $this->segment(ConnectionName::withoutWriteType($connection))
            . $this->segment($this->table)
            . $this->segment($partition?->key() ?? '')
            . ':';
    }

    /**
     * Get the key of the partition token.
     */
    protected function partitionTokenKey(string $prefix): string
    {
        return $prefix . 'p';
    }

    /**
     * Get the key of a scope's token.
     */
    protected function scopeTokenKey(string $prefix, int|string $scope): string
    {
        return $prefix . 's' . $this->segment($scope);
    }

    /**
     * Get the key of a feature's cached value for a scope.
     */
    protected function valueKey(string $prefix, int|string $feature, int|string $scope): string
    {
        return $prefix . 'v' . $this->segment($scope) . $this->segment($feature);
    }

    /**
     * Encode a key segment so adjacent segments can't run together.
     */
    protected function segment(int|string $value): string
    {
        $value = (string) $value;

        return strlen($value) . ':' . $value;
    }
}
