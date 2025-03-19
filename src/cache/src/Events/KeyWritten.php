<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

class KeyWritten extends CacheEvent
{
    /**
     * The value that was written.
     */
    public mixed $value;

    /**
     * The number of seconds the key should be valid.
     */
    public ?int $seconds;

    /**
     * Create a new event instance.
     */
    public function __construct(string $key, mixed $value, ?int $seconds = null, array $tags = [])
    {
        parent::__construct($key, $tags);

        $this->value = $value;
        $this->seconds = $seconds;
    }
}
