<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis;

use DateInterval;
use DateTimeInterface;
use Generator;
use Hypervel\Cache\AnyModeTaggedCache;
use Hypervel\Cache\Events\KeyWriteFailed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Events\WritingKey;
use Hypervel\Cache\Events\WritingManyKeys;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\TagSet;
use Hypervel\Contracts\Cache\Store;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * Any-mode tagged cache for Redis 8.0+ enhanced tagging.
 *
 * Uses Redis hashes with field expiration for tagged writes.
 */
class AnyTaggedCache extends AnyModeTaggedCache
{
    /**
     * The cache store implementation.
     *
     * @var RedisStore
     */
    protected Store $store;

    /**
     * The tag set instance.
     *
     * @var AnyTagSet
     */
    protected TagSet $tags;

    /**
     * Create a new tagged cache instance.
     */
    public function __construct(
        RedisStore $store,
        AnyTagSet $tags,
    ) {
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

        $result = $this->store->anyTagOps()->put()->execute($key, $value, $seconds, $this->tags->getNames());

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
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        if ($ttl === null) {
            return $this->putManyForever($values);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            $result = true;

            foreach (array_keys($values) as $key) {
                if (! $this->forgetPlainKey((string) $key)) {
                    $result = false;
                }
            }

            return $result;
        }

        $this->event(
            WritingManyKeys::class,
            fn (): WritingManyKeys => new WritingManyKeys(
                $this->getName(),
                array_map(static fn ($key): string => (string) $key, array_keys($values)),
                array_map(NullSentinel::unwrap(...), array_values($values)),
                $seconds
            )
        );

        $result = $this->store->anyTagOps()->putMany()->execute($values, $seconds, $this->tags->getNames());

        foreach ($values as $key => $value) {
            if ($result) {
                $this->event(
                    KeyWritten::class,
                    fn (): KeyWritten => new KeyWritten($this->getName(), (string) $key, NullSentinel::unwrap($value), $seconds)
                );
            } else {
                $this->event(
                    KeyWriteFailed::class,
                    fn (): KeyWriteFailed => new KeyWriteFailed($this->getName(), (string) $key, NullSentinel::unwrap($value), $seconds)
                );
            }
        }

        return $result;
    }

    /**
     * Store an item in the cache if the key does not exist.
     */
    public function add(UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        $seconds = null;

        if ($ttl !== null) {
            $seconds = $this->getSeconds($ttl);

            if ($seconds <= 0) {
                return false;
            }
        }

        return $this->store->anyTagOps()->add()->execute($key, $value, $seconds, $this->tags->getNames());
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

        $result = $this->store->anyTagOps()->forever()->execute($key, $value, $this->tags->getNames());

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

        return $this->store->anyTagOps()->increment()->execute($key, $value, $this->tags->getNames());
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(UnitEnum|string $key, int $value = 1): bool|int
    {
        $key = $key instanceof UnitEnum ? (string) enum_value($key) : $key;

        return $this->store->anyTagOps()->decrement()->execute($key, $value, $this->tags->getNames());
    }

    /**
     * Get all items (keys and values) tagged with the current tags.
     *
     * This is useful for debugging or bulk operations on tagged items.
     *
     * @return Generator<string, mixed>
     */
    public function items(): Generator
    {
        return $this->store->anyTagOps()->getTagItems()->execute($this->tags->getNames());
    }

    /**
     * Get the tag set instance (covariant return type).
     */
    public function getTags(): AnyTagSet
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
