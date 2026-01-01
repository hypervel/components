<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Store an item in the cache if it doesn't exist, with all tag tracking.
 *
 * Combines the ZADD operations for tag tracking with the atomic add
 * in a single connection checkout for efficiency.
 *
 * Uses Redis SET with NX (only set if Not eXists) and EX (expiration) flags
 * for atomic "add if not exists" semantics without requiring Lua scripts.
 *
 * Note: Tag entries are always added, even if the key exists. This matches
 * the original behavior where addEntry() is called before checking existence.
 */
class Add
{
    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Execute the add operation with tag tracking.
     *
     * @param string $key The cache key (already namespaced by caller)
     * @param mixed $value The value to store
     * @param int $seconds TTL in seconds
     * @param array<string> $tagIds Array of tag identifiers
     * @return bool True if the key was added (didn't exist), false if it already existed
     */
    public function execute(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $value, $seconds, $tagIds);
        }

        return $this->executePipeline($key, $value, $seconds, $tagIds);
    }

    /**
     * Execute using pipeline for standard Redis (non-cluster).
     *
     * Pipelines ZADD commands for all tags, then uses SET NX EX for atomic add.
     */
    private function executePipeline(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $seconds, $tagIds) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $score = now()->addSeconds($seconds)->getTimestamp();

            // Pipeline the ZADD operations for tag tracking
            if (! empty($tagIds)) {
                $pipeline = $client->pipeline();

                foreach ($tagIds as $tagId) {
                    $pipeline->zadd($prefix . $tagId, $score, $key);
                }

                $pipeline->exec();
            }

            // SET key value EX seconds NX - atomic "add if not exists"
            $result = $client->set(
                $prefix . $key,
                $this->serialization->serialize($conn, $value),
                ['EX' => max(1, $seconds), 'NX']
            );

            return (bool) $result;
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     *
     * Sequential ZADD commands since tags may be in different slots,
     * then SET NX EX for atomic add.
     */
    private function executeCluster(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $conn) use ($key, $value, $seconds, $tagIds) {
            $client = $conn->client();
            $prefix = $this->context->prefix();
            $score = now()->addSeconds($seconds)->getTimestamp();

            // ZADD to each tag's sorted set (sequential - cross-slot)
            foreach ($tagIds as $tagId) {
                $client->zadd($prefix . $tagId, $score, $key);
            }

            // SET key value EX seconds NX - atomic "add if not exists"
            $result = $client->set(
                $prefix . $key,
                $this->serialization->serialize($conn, $value),
                ['EX' => max(1, $seconds), 'NX']
            );

            return (bool) $result;
        });
    }
}
