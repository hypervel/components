<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Operations\AnyTag\Add;
use Hypervel\Cache\Redis\Operations\AnyTag\Decrement;
use Hypervel\Cache\Redis\Operations\AnyTag\Flush;
use Hypervel\Cache\Redis\Operations\AnyTag\Forever;
use Hypervel\Cache\Redis\Operations\AnyTag\GetTaggedKeys;
use Hypervel\Cache\Redis\Operations\AnyTag\GetTagItems;
use Hypervel\Cache\Redis\Operations\AnyTag\Increment;
use Hypervel\Cache\Redis\Operations\AnyTag\Prune;
use Hypervel\Cache\Redis\Operations\AnyTag\Put;
use Hypervel\Cache\Redis\Operations\AnyTag\PutMany;
use Hypervel\Cache\Redis\Operations\AnyTag\Remember;
use Hypervel\Cache\Redis\Operations\AnyTag\RememberForever;
use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;

/**
 * Container for any-mode tag operations.
 *
 * This class groups all Redis operations related to any-mode tagging,
 * providing lazy-loaded, singleton-cached operation instances.
 *
 * Used by AnyTaggedCache and AnyTagSet.
 */
class AnyTagOperations
{
    private ?Put $put = null;

    private ?PutMany $putMany = null;

    private ?Add $add = null;

    private ?Forever $forever = null;

    private ?Increment $increment = null;

    private ?Decrement $decrement = null;

    private ?GetTaggedKeys $getTaggedKeys = null;

    private ?GetTagItems $getTagItems = null;

    private ?Flush $flush = null;

    private ?Prune $prune = null;

    private ?Remember $remember = null;

    private ?RememberForever $rememberForever = null;

    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Get the Put operation for storing items with tags.
     */
    public function put(): Put
    {
        return $this->put ??= new Put($this->context, $this->serialization);
    }

    /**
     * Get the PutMany operation for storing multiple items with tags.
     */
    public function putMany(): PutMany
    {
        return $this->putMany ??= new PutMany($this->context, $this->serialization);
    }

    /**
     * Get the Add operation for storing items if they don't exist.
     */
    public function add(): Add
    {
        return $this->add ??= new Add($this->context, $this->serialization);
    }

    /**
     * Get the Forever operation for storing items indefinitely with tags.
     */
    public function forever(): Forever
    {
        return $this->forever ??= new Forever($this->context, $this->serialization);
    }

    /**
     * Get the Increment operation for incrementing values with tags.
     */
    public function increment(): Increment
    {
        return $this->increment ??= new Increment($this->context);
    }

    /**
     * Get the Decrement operation for decrementing values with tags.
     */
    public function decrement(): Decrement
    {
        return $this->decrement ??= new Decrement($this->context);
    }

    /**
     * Get the GetTaggedKeys operation for retrieving keys associated with a tag.
     */
    public function getTaggedKeys(): GetTaggedKeys
    {
        return $this->getTaggedKeys ??= new GetTaggedKeys($this->context);
    }

    /**
     * Get the GetTagItems operation for retrieving key-value pairs for tags.
     */
    public function getTagItems(): GetTagItems
    {
        return $this->getTagItems ??= new GetTagItems(
            $this->context,
            $this->serialization,
            $this->getTaggedKeys()
        );
    }

    /**
     * Get the Flush operation for removing all items with specified tags.
     */
    public function flush(): Flush
    {
        return $this->flush ??= new Flush($this->context, $this->getTaggedKeys());
    }

    /**
     * Get the Prune operation for removing orphaned fields from tag hashes.
     *
     * This removes expired tags from the registry, scans active tag hashes
     * for fields referencing deleted cache keys, and deletes empty hashes.
     */
    public function prune(): Prune
    {
        return $this->prune ??= new Prune($this->context);
    }

    /**
     * Get the Remember operation for cache-through with tags.
     *
     * This operation is optimized to use a single connection for both
     * GET and PUT operations, avoiding double pool overhead on cache misses.
     */
    public function remember(): Remember
    {
        return $this->remember ??= new Remember($this->context, $this->serialization);
    }

    /**
     * Get the RememberForever operation for cache-through with tags (no TTL).
     *
     * This operation is optimized to use a single connection for both
     * GET and SET operations, avoiding double pool overhead on cache misses.
     */
    public function rememberForever(): RememberForever
    {
        return $this->rememberForever ??= new RememberForever($this->context, $this->serialization);
    }

    /**
     * Clear all cached operation instances.
     *
     * Called when the store's connection or prefix changes.
     */
    public function clear(): void
    {
        $this->put = null;
        $this->putMany = null;
        $this->add = null;
        $this->forever = null;
        $this->increment = null;
        $this->decrement = null;
        $this->getTaggedKeys = null;
        $this->getTagItems = null;
        $this->flush = null;
        $this->prune = null;
        $this->remember = null;
        $this->rememberForever = null;
    }
}
