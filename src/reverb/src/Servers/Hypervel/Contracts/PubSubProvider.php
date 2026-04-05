<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel\Contracts;

interface PubSubProvider
{
    /**
     * Connect to the pub/sub provider.
     */
    public function connect(): void;

    /**
     * Disconnect from the pub/sub provider.
     */
    public function disconnect(): void;

    /**
     * Subscribe to messages.
     */
    public function subscribe(): void;

    /**
     * Listen for a given event type.
     */
    public function on(string $event, callable $callback): void;

    /**
     * Listen for the given event.
     */
    public function listen(string $event, callable $callback): void;

    /**
     * Stop listening for the given event.
     */
    public function stopListening(string $event): void;

    /**
     * Publish a payload.
     *
     * @return int Number of subscribers that received the message
     */
    public function publish(array $payload): int;
}
