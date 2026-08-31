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
 * Tag entries are published after the value attempt even when the key already
 * exists, preserving the existing membership behavior without exposing a
 * metadata-before-value window to concurrent pruning.
 */
class Add
{
    /**
     * Create a new add operation instance.
     */
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
     * @param int $seconds TTL in seconds; values below one are stored for one second
     * @param array<string> $tagIds Array of tag identifiers
     * @return bool True if the key was added (didn't exist), false if it already existed
     */
    public function execute(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        $seconds = max(1, $seconds);

        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $value, $seconds, $tagIds);
        }

        return $this->executePipeline($key, $value, $seconds, $tagIds);
    }

    /**
     * Execute using pipeline for standard Redis (non-cluster).
     *
     * Uses SET NX EX for atomic add, then pipelines ZADD commands for all tags.
     */
    private function executePipeline(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $value, $seconds, $tagIds) {
            $prefix = $this->context->prefix();
            $score = $this->context->expirationScore($seconds);

            // Publish the value attempt before its memberships so concurrent
            // pruning cannot mistake a newly written member for an orphan.
            $result = $connection->set(
                $prefix . $key,
                $this->serialization->serialize($connection, $value),
                ['EX' => $seconds, 'NX']
            );

            // Membership is unconditional to preserve existing-key behavior.
            if (! empty($tagIds)) {
                $pipeline = $connection->pipeline();

                foreach ($tagIds as $tagId) {
                    $pipeline->zadd($prefix . $tagId, $score, $key);
                }

                $pipeline->exec();
            }

            return (bool) $result;
        });
    }

    /**
     * Execute using sequential commands for Redis Cluster.
     *
     * Uses SET NX EX for atomic add, then sequential ZADD commands because
     * tags may be in different slots.
     */
    private function executeCluster(string $key, mixed $value, int $seconds, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $value, $seconds, $tagIds) {
            $prefix = $this->context->prefix();
            $score = $this->context->expirationScore($seconds);

            // Publish the value attempt before its memberships so concurrent
            // pruning can repair cross-slot races without losing fresh metadata.
            $result = $connection->set(
                $prefix . $key,
                $this->serialization->serialize($connection, $value),
                ['EX' => $seconds, 'NX']
            );

            // Membership is unconditional to preserve existing-key behavior.
            foreach ($tagIds as $tagId) {
                $connection->zadd($prefix . $tagId, $score, $key);
            }

            return (bool) $result;
        });
    }
}
