<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Http;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Protocols\Pusher\Managers\ScopedChannelManager;

final readonly class VerifiedRequestContext
{
    /**
     * Create a new verified request context instance.
     */
    public function __construct(
        public Application $application,
        public ScopedChannelManager $channels,
        public string $body,
        public array $query,
    ) {
    }
}
