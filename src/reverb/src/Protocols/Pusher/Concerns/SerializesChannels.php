<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Concerns;

use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;

trait SerializesChannels
{
    /**
     * Prepare the channel instance values for serialization.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'name' => $this->name,
        ];
    }

    /**
     * Restore the channel after serialization.
     */
    public function __unserialize(array $values): void
    {
        $this->name = $values['name'];
        $this->connections = app(ChannelConnectionManager::class)->for($this->name);
    }
}
