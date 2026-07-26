<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Operations\AllTag\Add;
use Hypervel\Cache\Redis\Operations\AllTag\AddEntry;
use Hypervel\Cache\Redis\Operations\AllTag\Decrement;
use Hypervel\Cache\Redis\Operations\AllTag\Flush;
use Hypervel\Cache\Redis\Operations\AllTag\FlushStale;
use Hypervel\Cache\Redis\Operations\AllTag\Forever;
use Hypervel\Cache\Redis\Operations\AllTag\GetEntries;
use Hypervel\Cache\Redis\Operations\AllTag\Increment;
use Hypervel\Cache\Redis\Operations\AllTag\Prune;
use Hypervel\Cache\Redis\Operations\AllTag\Put;
use Hypervel\Cache\Redis\Operations\AllTag\PutMany;
use Hypervel\Cache\Redis\Operations\AllTag\Touch;
use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;

class AllTagOperations
{
    // Combined cache + tag operations
    private ?Put $put = null;

    private ?PutMany $putMany = null;

    private ?Add $add = null;

    private ?Forever $forever = null;

    private ?Touch $touch = null;

    private ?Increment $increment = null;

    private ?Decrement $decrement = null;

    // Tag management operations
    private ?AddEntry $addEntry = null;

    private ?GetEntries $getEntries = null;

    private ?FlushStale $flushStale = null;

    private ?Flush $flush = null;

    private ?Prune $prune = null;

    public function __construct(
        private readonly StoreContext $context,
        private readonly Serialization $serialization,
    ) {
    }

    /**
     * Get the Put operation for storing items with tag tracking.
     */
    public function put(): Put
    {
        return $this->put ??= new Put($this->context, $this->serialization);
    }

    /**
     * Get the PutMany operation for storing multiple items with tag tracking.
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
     * Get the Forever operation for storing items indefinitely with tag tracking.
     */
    public function forever(): Forever
    {
        return $this->forever ??= new Forever($this->context, $this->serialization);
    }

    /**
     * Get the Touch operation for adjusting tagged item expiration.
     */
    public function touch(): Touch
    {
        return $this->touch ??= new Touch($this->context);
    }

    /**
     * Get the Increment operation for incrementing values with tag tracking.
     */
    public function increment(): Increment
    {
        return $this->increment ??= new Increment($this->context);
    }

    /**
     * Get the Decrement operation for decrementing values with tag tracking.
     */
    public function decrement(): Decrement
    {
        return $this->decrement ??= new Decrement($this->context);
    }

    /**
     * Get the AddEntry operation for adding cache key references to tag sorted sets.
     *
     * Not currently called internally — the combined operation classes (Put, Forever,
     * Increment, etc.) handle tag tracking inline for single-checkout pool efficiency.
     * Kept for Laravel RedisTagSet API parity.
     */
    public function addEntry(): AddEntry
    {
        return $this->addEntry ??= new AddEntry($this->context);
    }

    /**
     * Get the GetEntries operation for retrieving cache keys from tag sorted sets.
     */
    public function getEntries(): GetEntries
    {
        return $this->getEntries ??= new GetEntries($this->context);
    }

    /**
     * Get the FlushStale operation for removing expired entries from tag sorted sets.
     */
    public function flushStale(): FlushStale
    {
        return $this->flushStale ??= new FlushStale($this->context);
    }

    /**
     * Get the Flush operation for removing all items with specified tags.
     */
    public function flush(): Flush
    {
        return $this->flush ??= new Flush($this->context, $this->getEntries());
    }

    /**
     * Get the Prune operation for removing stale entries from all tag sorted sets.
     *
     * This discovers all tag:*:entries keys via SCAN and removes entries
     * with expired TTL scores, then deletes empty sorted sets.
     */
    public function prune(): Prune
    {
        return $this->prune ??= new Prune($this->context);
    }
}
