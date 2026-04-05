<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

final readonly class ChannelBroadcastPipeMessage
{
    /**
     * Create a new channel broadcast pipe message.
     */
    public function __construct(
        public string $appId,
        public array $channels,
        public array $payload,
        public ?string $exceptSocketId,
        public bool $internal = false,
    ) {
    }
}
