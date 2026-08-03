<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Throwable;

class UserConnectionTerminator
{
    /**
     * Create a new user connection terminator.
     */
    public function __construct(
        protected ChannelManager $channels,
    ) {
    }

    /**
     * Disconnect every local connection for the given user.
     */
    public function terminate(Application $application, string $userId): void
    {
        $exception = null;

        foreach ($this->channels->for($application)->connections() as $connection) {
            if ((string) ($connection->data()['user_id'] ?? '') !== $userId) {
                continue;
            }

            try {
                $connection->disconnect();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }
}
