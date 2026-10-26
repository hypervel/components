<?php

declare(strict_types=1);

namespace Hypervel\Queue\Outbox;

readonly class OutboxMessage
{
    /**
     * Create a claimed outgoing message without retaining its database connection.
     */
    public function __construct(
        public int $id,
        public string $uuid,
        public string $leaseToken,
        public int $attempts,
        public string $connection,
        public ?string $queue,
        public string $payload,
        public array $options,
        public ?int $deliverAt,
        public int $eligibleAt,
        public int $createdAt,
    ) {
    }
}
