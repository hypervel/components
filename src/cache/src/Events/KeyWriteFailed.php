<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

use UnitEnum;

class KeyWriteFailed extends CacheEvent
{
    /**
     * The value that would have been written.
     */
    public mixed $value;

    /**
     * The number of seconds the key should have been valid.
     */
    public ?int $seconds;

    /**
     * Create a new event instance.
     */
    public function __construct(?string $storeName, UnitEnum|string $key, mixed $value, ?int $seconds = null, array $tags = [])
    {
        parent::__construct($storeName, $key, $tags);

        $this->value = $value;
        $this->seconds = $seconds;
    }
}
