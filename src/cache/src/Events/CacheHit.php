<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

class CacheHit extends CacheEvent
{
    /**
     * The value that was retrieved.
     */
    public mixed $value;

    /**
     * Create a new event instance.
     */
    public function __construct(string $key, mixed $value, array $tags = [])
    {
        parent::__construct($key, $tags);

        $this->value = $value;
    }
}
