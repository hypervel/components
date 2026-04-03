<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Fixtures;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Concerns\GeneratesIdentifiers;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Contracts\Connection as BaseConnection;
use Hypervel\Testing\Assert;

class FakeConnection extends BaseConnection
{
    use GeneratesIdentifiers;

    /**
     * Messages received by the connection.
     *
     * @var array<int, string>
     */
    public array $messages = [];

    /**
     * Whether the connection has been terminated.
     */
    public bool $wasTerminated = false;

    /**
     * Connection identifier.
     */
    public string $identifier = '19c1c8e8-351b-4eb5-b6d9-6cbfc54a3446';

    /**
     * Connection socket ID.
     */
    public ?string $id = null;

    /**
     * Create a new fake connection instance.
     */
    public function __construct(?string $identifier = null, ?string $origin = null)
    {
        if ($identifier) {
            $this->identifier = $identifier;
        }

        $this->origin = $origin ?? 'http://localhost';
        $this->lastSeenAt = time();
    }

    /**
     * Get the raw socket connection identifier.
     */
    public function identifier(): string
    {
        return $this->identifier;
    }

    /**
     * Get the normalized socket ID.
     */
    public function id(): string
    {
        if (! $this->id) {
            $this->id = $this->generateId();
        }

        return $this->id;
    }

    /**
     * Get the application the connection belongs to.
     */
    public function app(): Application
    {
        return app(ApplicationProvider::class)->findByKey('reverb-key');
    }

    /**
     * Set the connection last seen at timestamp.
     */
    public function setLastSeenAt(int $time): static
    {
        $this->lastSeenAt = $time;

        return $this;
    }

    /**
     * Set the connection as pinged.
     */
    public function setHasBeenPinged(): void
    {
        $this->hasBeenPinged = true;
    }

    /**
     * Send a message to the connection.
     */
    public function send(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * Send a control frame to the connection.
     */
    public function control(int $opcode = WEBSOCKET_OPCODE_PING): void
    {
    }

    /**
     * Terminate a connection.
     */
    public function terminate(): void
    {
        $this->wasTerminated = true;
    }

    /**
     * Reset the received messages.
     */
    public function resetReceived(): void
    {
        $this->messages = [];
    }

    /**
     * Assert the given message was received by the connection.
     */
    public function assertReceived(array $message): void
    {
        Assert::assertContains(json_encode($message), $this->messages);
    }

    /**
     * Assert the connection received the given message count.
     */
    public function assertReceivedCount(int $count): void
    {
        Assert::assertCount($count, $this->messages);
    }

    /**
     * Assert the connection didn't receive any messages.
     */
    public function assertNothingReceived(): void
    {
        Assert::assertEmpty($this->messages);
    }

    /**
     * Assert the connection has been pinged.
     */
    public function assertHasBeenPinged(): void
    {
        Assert::assertTrue($this->hasBeenPinged);
    }

    /**
     * Assert the connection has been terminated.
     */
    public function assertHasBeenTerminated(): void
    {
        Assert::assertTrue($this->wasTerminated);
    }
}
