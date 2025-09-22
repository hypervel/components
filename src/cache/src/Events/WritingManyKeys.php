<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

class WritingManyKeys extends CacheEvent
{
    /**
     * The keys that are being written.
     */
    public array $keys;

    /**
     * The values that are being written.
     */
    public array $values;

    /**
     * The number of seconds the keys should be valid.
     */
    public ?int $seconds;

    /**
     * Create a new event instance.
     */
    public function __construct(?string $storeName, array $keys, array $values, ?int $seconds = null, array $tags = [])
    {
        parent::__construct($storeName, $keys[0] ?? '', $tags);

        $this->keys = $keys;
        $this->values = $values;
        $this->seconds = $seconds;
    }
}
