<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server;

use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Support\CarbonImmutable;

readonly class ServerCallContext
{
    /**
     * Create a server call context.
     *
     * @internal
     */
    public function __construct(
        private Metadata $metadata,
        private string $service,
        private string $method,
        private string $peer,
        private ?CarbonImmutable $deadline,
        private Deadline $monotonicDeadline,
        private int $previousAttempts,
    ) {
    }

    /**
     * Return the request metadata.
     */
    public function metadata(): Metadata
    {
        return $this->metadata;
    }

    /**
     * Return the fully qualified service name.
     */
    public function service(): string
    {
        return $this->service;
    }

    /**
     * Return the service method name.
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Return the remote peer address.
     */
    public function peer(): string
    {
        return $this->peer;
    }

    /**
     * Return the wall-clock deadline.
     */
    public function deadline(): ?CarbonImmutable
    {
        return $this->deadline;
    }

    /**
     * Return the remaining deadline in seconds.
     *
     * @phpstan-impure
     */
    public function timeRemaining(): ?float
    {
        return $this->monotonicDeadline->remainingSeconds();
    }

    /**
     * Determine whether the deadline has expired.
     *
     * @phpstan-impure
     */
    public function deadlineExceeded(): bool
    {
        return $this->monotonicDeadline->expired();
    }

    /**
     * Return the number of completed previous RPC attempts.
     */
    public function previousAttempts(): int
    {
        return $this->previousAttempts;
    }
}
