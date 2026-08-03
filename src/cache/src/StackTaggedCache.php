<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\Events\KeyWriteFailed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Events\WritingKey;
use Hypervel\Contracts\Cache\Store;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * Any-mode tagged cache for the stack store.
 *
 * Writes push stack records through every layer: tagged writes on taggable
 * layers so indexes are recorded, plain writes above. Reads are not tag
 * operations; use the plain stack repository for L1 hits and L2 backfill.
 */
class StackTaggedCache extends AnyModeTaggedCache
{
    /**
     * The cache store implementation.
     *
     * @var StackStore
     */
    protected Store $store;

    /**
     * The tag set instance.
     *
     * @var StackTagSet
     */
    protected TagSet $tags;

    /**
     * Create a new tagged cache instance.
     */
    public function __construct(StackStore $store, StackTagSet $tags)
    {
        parent::__construct($store, $tags);
    }

    /**
     * Store an item in the cache.
     */
    public function put(array|UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        if (is_array($key)) {
            return $this->putMany($key, $value);
        }

        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        if ($ttl === null) {
            return $this->forever($key, $value);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            return $this->forgetPlainKey($key);
        }

        $this->event(
            WritingKey::class,
            fn (): WritingKey => new WritingKey($this->getName(), $key, NullSentinel::unwrap($value), $seconds)
        );

        $result = $this->store->putRecordTagged($this->tags->getNames(), $key, [
            'value' => $value,
            'ttl' => $seconds,
        ]);

        if ($result) {
            $this->event(
                KeyWritten::class,
                fn (): KeyWritten => new KeyWritten($this->getName(), $key, NullSentinel::unwrap($value), $seconds)
            );
        } else {
            $this->event(
                KeyWriteFailed::class,
                fn (): KeyWriteFailed => new KeyWriteFailed($this->getName(), $key, NullSentinel::unwrap($value), $seconds)
            );
        }

        return $result;
    }

    /**
     * Store an item in the cache if the key does not exist.
     */
    public function add(UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        if (! is_null($this->getPlainRaw($key))) {
            return false;
        }

        return $this->put($key, $value, $ttl);
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(UnitEnum|string $key, mixed $value): bool
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        $this->event(WritingKey::class, fn (): WritingKey => new WritingKey(
            $this->getName(),
            $key,
            NullSentinel::unwrap($value)
        ));

        $result = $this->store->putRecordTagged($this->tags->getNames(), $key, ['value' => $value]);

        if ($result) {
            $this->event(
                KeyWritten::class,
                fn (): KeyWritten => new KeyWritten($this->getName(), $key, NullSentinel::unwrap($value))
            );
        } else {
            $this->event(
                KeyWriteFailed::class,
                fn (): KeyWriteFailed => new KeyWriteFailed($this->getName(), $key, NullSentinel::unwrap($value))
            );
        }

        return $result;
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(UnitEnum|string $key, int $value = 1): bool|int
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        return $this->store->incrementTagged($this->tags->getNames(), $key, $value);
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(UnitEnum|string $key, int $value = 1): bool|int
    {
        return $this->increment($key, $value * -1);
    }

    /**
     * Get the tag set instance (covariant return type).
     */
    public function getTags(): StackTagSet
    {
        return $this->tags;
    }

    /**
     * Store multiple items in the cache indefinitely.
     */
    protected function putManyForever(array $values): bool
    {
        $result = true;

        foreach ($values as $key => $value) {
            if (! $this->forever((string) $key, $value)) {
                $result = false;
            }
        }

        return $result;
    }
}
