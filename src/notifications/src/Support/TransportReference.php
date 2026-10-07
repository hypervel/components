<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Support;

/**
 * @internal
 */
readonly class TransportReference
{
    /**
     * Identify an object within one transport snapshot.
     */
    public function __construct(public int $index)
    {
    }
}
