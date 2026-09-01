<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

use Throwable;
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
     * The exception raised while writing the key, if one was thrown.
     */
    public ?Throwable $exception;

    /**
     * Create a new event instance.
     */
    public function __construct(
        ?string $storeName,
        UnitEnum|string $key,
        mixed $value,
        ?int $seconds = null,
        array $tags = [],
        ?Throwable $exception = null,
    ) {
        parent::__construct($storeName, $key, $tags);

        $this->value = $value;
        $this->seconds = $seconds;
        $this->exception = $exception;
    }
}
