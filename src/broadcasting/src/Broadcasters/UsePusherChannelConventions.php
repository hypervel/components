<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Broadcasters;

use Hypervel\Support\Str;

trait UsePusherChannelConventions
{
    /**
     * Return true if the channel is protected by authentication.
     */
    public function isGuardedChannel(string $channel): bool
    {
        return Str::startsWith($channel, ['private-', 'presence-']);
    }

    /**
     * Remove prefix from channel name.
     */
    public function normalizeChannelName(string $channel): string
    {
        foreach (['private-encrypted-', 'private-', 'presence-'] as $prefix) {
            if (Str::startsWith($channel, $prefix)) {
                return Str::replaceFirst($prefix, '', $channel);
            }
        }

        return $channel;
    }
}
