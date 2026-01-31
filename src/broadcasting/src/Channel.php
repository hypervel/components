<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting;

use Hypervel\Contracts\Broadcasting\HasBroadcastChannel;
use Stringable;

class Channel implements Stringable
{
    /**
     * The channel's name.
     */
    public string $name;

    /**
     * Create a new channel instance.
     */
    public function __construct(HasBroadcastChannel|string $name)
    {
        $this->name = $name instanceof HasBroadcastChannel ? $name->broadcastChannel() : $name;
    }

    /**
     * Convert the channel instance to a string.
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
