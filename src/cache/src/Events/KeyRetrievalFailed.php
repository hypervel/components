<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

use Throwable;
use UnitEnum;

class KeyRetrievalFailed extends CacheEvent
{
    /**
     * The exception raised while retrieving the key.
     */
    public Throwable $exception;

    /**
     * Create a new event instance.
     */
    public function __construct(?string $storeName, UnitEnum|string $key, Throwable $exception, array $tags = [])
    {
        parent::__construct($storeName, $key, $tags);

        $this->exception = $exception;
    }
}
