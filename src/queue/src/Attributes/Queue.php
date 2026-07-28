<?php

declare(strict_types=1);

namespace Hypervel\Queue\Attributes;

use Attribute;
use UnitEnum;

use function Hypervel\Support\enum_value;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class Queue
{
    public string $queue;

    /**
     * Create a new attribute instance.
     */
    public function __construct(UnitEnum|string $queue)
    {
        $this->queue = (string) enum_value($queue);
    }
}
