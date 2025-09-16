<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

class WritingKey extends CacheEvent
{
    /**
     * The value that will be written.
     */
    public mixed $value;

    /**
     * The number of seconds the key should be valid.
     */
    public ?int $seconds;

    /**
     * Create a new event instance.
     */
    public function __construct(?string $storeName, string $key, mixed $value, ?int $seconds = null, array $tags = [])
    {
        parent::__construct($storeName, $key, $tags);

        $this->value = $value;
        $this->seconds = $seconds;
    }
}