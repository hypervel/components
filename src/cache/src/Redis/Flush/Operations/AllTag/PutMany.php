<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store multiple items in the cache with all tag tracking.
 *
 * Combines the ZADD operations for all keys to all tags with SETEX
 * for each cache value in a single pipeline for efficiency.
 */
class PutMany
{
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {}

    /**
     * Execute the putMany operation with tag tracking.
     *
     * @param array<string, mixed> $values Key-value pairs (keys already namespaced)
     * @param int $seconds TTL in seconds
     * @param array<string> $tagIds Array of tag identifiers
     * @param string $namespace The namespace prefix for keys (for building namespaced keys)
     * @return bool True if all operations successful
     */
    public function execute(array $values, int $seconds, array $tagIds, string $namespace): bool
    {
        if (empty($values)) {
            return true;
        }

        if ($this->context->isCluster()) {
            return $this->executeCluster($values, $seconds, $tagIds, $namespace);
        }

        return $this->executePipeline($values, $seconds, $tagIds, $namespace);
    }

    /**
     * Execute using pipeline for standard Redis (non-cluster).
     *
     * Uses variadic ZADD to batch all cache keys into a single command per tag,
     * reducing the total number of Redis commands from O(keys × tags) to O(tags + keys).
     */
    private function executePipeline(array $values, int $seconds, array $tagIds, string $namespace): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($values, $seconds, $tagIds, $namespace) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $score = now()->addSeconds($seconds)->getTimestamp();
            $ttl = max(1, $seconds);

            // Prepare all data upfront
            $preparedEntries = [];
            foreach ($values as $key => $value) {
                $namespacedKey = $namespace . $key;
                $preparedEntries[$namespacedKey] = $this->serialization->serialize($conn, $value);
            }

            $namespacedKeys = array_keys($preparedEntries);

            $pipeline = $client->pipeline();

            // Batch ZADD: one command per tag with all cache keys as members
            // ZADD format: key, score1, member1, score2, member2, ...
            foreach ($tagIds as $tagId) {
                $zaddArgs = [];
                foreach ($namespacedKeys as $key) {
                    $zaddArgs[] = $score;
                    $zaddArgs[] = $key;
                }
                $pipeline->zadd($prefix . $tagId, ...$zaddArgs);
            }

            // Then all SETEXs
            foreach ($preparedEntries as $namespacedKey => $serialized) {
                $pipeline->setex($prefix . $namespacedKey, $ttl, $serialized);
            }

            $results = $pipeline->exec();

            return $results !== false && ! in_array(false, $results, true);
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     *
     * Uses variadic ZADD to batch all cache keys into a single command per tag.
     * This is safe in cluster mode because variadic ZADD targets ONE sorted set key,
     * which resides in a single slot.
     */
    private function executeCluster(array $values, int $seconds, array $tagIds, string $namespace): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($values, $seconds, $tagIds, $namespace) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $score = now()->addSeconds($seconds)->getTimestamp();
            $ttl = max(1, $seconds);

            // Prepare all data upfront
            $preparedEntries = [];
            foreach ($values as $key => $value) {
                $namespacedKey = $namespace . $key;
                $preparedEntries[$namespacedKey] = $this->serialization->serialize($conn, $value);
            }

            $namespacedKeys = array_keys($preparedEntries);

            // Batch ZADD: one command per tag with all cache keys as members
            // Each tag's sorted set is in ONE slot, so variadic ZADD works in cluster
            foreach ($tagIds as $tagId) {
                $zaddArgs = [];
                foreach ($namespacedKeys as $key) {
                    $zaddArgs[] = $score;
                    $zaddArgs[] = $key;
                }
                $client->zadd($prefix . $tagId, ...$zaddArgs);
            }

            // Then all SETEXs
            $allSucceeded = true;
            foreach ($preparedEntries as $namespacedKey => $serialized) {
                if (! $client->setex($prefix . $namespacedKey, $ttl, $serialized)) {
                    $allSucceeded = false;
                }
            }

            return $allSucceeded;
        });
    }
}
