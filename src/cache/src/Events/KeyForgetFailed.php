<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

use Throwable;
use UnitEnum;

class KeyForgetFailed extends CacheEvent
{
    /**
     * The exception raised while forgetting the key, if one was thrown.
     */
    public ?Throwable $exception;

    /**
     * Create a new event instance.
     */
    public function __construct(
        ?string $storeName,
        UnitEnum|string $key,
        array $tags = [],
        ?Throwable $exception = null,
    ) {
        parent::__construct($storeName, $key, $tags);

        $this->exception = $exception;
    }
}
