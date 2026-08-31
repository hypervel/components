<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store multiple items in the cache with all tag tracking.
 *
 * Combines SETEX for each cache value with ZADD operations for all keys and
 * tags in a single pipeline for efficiency.
 */
class PutMany
{
    /**
     * Create a new put-many operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the putMany operation with tag tracking.
     *
     * @param array<string, mixed> $values Key-value pairs (keys already namespaced)
     * @param int $seconds TTL in seconds; values below one are stored for one second
     * @param array<string> $tagIds Array of tag identifiers
     * @param string $namespace The namespace prefix for keys (for building namespaced keys)
     * @return bool True if all operations successful
     */
    public function execute(array $values, int $seconds, array $tagIds, string $namespace): bool
    {
        if (empty($values)) {
            return true;
        }

        $seconds = max(1, $seconds);

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
        return $this->context->withConnection(function (RedisConnection $connection) use ($values, $seconds, $tagIds, $namespace): bool {
            $prefix = $this->context->prefix();
            $score = $this->context->expirationScore($seconds);

            // Prepare all data up front
            $preparedEntries = [];
            foreach ($values as $key => $value) {
                $namespacedKey = $namespace . $key;
                $preparedEntries[$namespacedKey] = $this->serialization->serialize($connection, $value);
            }

            $namespacedKeys = array_keys($preparedEntries);

            $pipeline = $connection->pipeline();

            // Publish values before their memberships so concurrent pruning
            // cannot mistake newly written members for orphans.
            foreach ($preparedEntries as $namespacedKey => $serialized) {
                $pipeline->setex($prefix . $namespacedKey, $seconds, $serialized);
            }

            // Pipeline results are unavailable until exec(), so memberships
            // cannot be filtered after failed writes without another round trip.
            // Batch ZADD: one command per tag with all cache keys as members
            // ZADD format: key, score1, member1, score2, member2, ...
            foreach ($tagIds as $tagId) {
                $zaddArguments = [];
                foreach ($namespacedKeys as $key) {
                    $zaddArguments[] = $score;
                    $zaddArguments[] = $key;
                }
                $pipeline->zadd($prefix . $tagId, ...$zaddArguments);
            }

            $results = $pipeline->exec();

            return $results !== false && ! in_array(false, $results, true);
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     *
     * Uses variadic ZADD to batch all cache keys into a single command per tag.
     * This is safe in cluster mode because variadic ZADD targets one sorted set key,
     * which resides in a single slot.
     */
    private function executeCluster(array $values, int $seconds, array $tagIds, string $namespace): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($values, $seconds, $tagIds, $namespace): bool {
            $prefix = $this->context->prefix();
            $score = $this->context->expirationScore($seconds);

            // Prepare all data up front
            $preparedEntries = [];
            foreach ($values as $key => $value) {
                $namespacedKey = $namespace . $key;
                $preparedEntries[$namespacedKey] = $this->serialization->serialize($connection, $value);
            }

            // Publish values before their memberships so concurrent pruning
            // can repair cross-slot races without losing fresh metadata.
            $allSucceeded = true;
            $namespacedKeys = [];

            foreach ($preparedEntries as $namespacedKey => $serialized) {
                if ($connection->setex($prefix . $namespacedKey, $seconds, $serialized)) {
                    $namespacedKeys[] = $namespacedKey;
                } else {
                    $allSucceeded = false;
                }
            }

            if ($namespacedKeys === []) {
                return false;
            }

            // Batch ZADD: one command per tag with all cache keys as members
            // Each tag's sorted set is in one slot, so variadic ZADD works in cluster
            foreach ($tagIds as $tagId) {
                $zaddArguments = [];
                foreach ($namespacedKeys as $key) {
                    $zaddArguments[] = $score;
                    $zaddArguments[] = $key;
                }
                $connection->zadd($prefix . $tagId, ...$zaddArguments);
            }

            return $allSucceeded;
        });
    }
}
