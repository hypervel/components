<?php

declare(strict_types=1);

namespace Hypervel\Cache\Events;

use Throwable;

class ManyKeysRetrievalFailed extends CacheEvent
{
    /**
     * The keys that could not be retrieved.
     *
     * @var list<string>
     */
    public array $keys;

    /**
     * The exception raised while retrieving the keys.
     */
    public Throwable $exception;

    /**
     * Create a new event instance.
     *
     * @param list<string> $keys
     */
    public function __construct(?string $storeName, array $keys, Throwable $exception, array $tags = [])
    {
        parent::__construct($storeName, $keys[0] ?? '', $tags);

        $this->keys = $keys;
        $this->exception = $exception;
    }
}
