<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Broadcasters;

use Ably\PubSub\Models\Message as AblyMessage;
use Ably\PubSub\PubSubHttpClient;
use Hypervel\Contracts\Container\Container;
use Override;

/**
 * @template TClient of PubSubHttpClient = PubSubHttpClient
 *
 * @extends AblyBroadcaster<TClient>
 */
class Ably2Broadcaster extends AblyBroadcaster
{
    /**
     * Create a new broadcaster instance.
     *
     * @param TClient $ably
     */
    public function __construct(Container $container, PubSubHttpClient $ably)
    {
        $this->container = $container;
        $this->ably = $ably;
    }

    // Inherit broadcast() because the parent handles both SDKs' exceptions and channel cleanup.

    /**
     * Build an Ably message object for broadcasting.
     */
    #[Override]
    protected function buildAblyMessage(string $event, array $payload = []): AblyMessage
    {
        return tap(new AblyMessage, function ($message) use ($event, $payload) {
            $message->name = $event;
            $message->data = $payload;
            $message->connectionKey = data_get($payload, 'socket');
        });
    }
}
