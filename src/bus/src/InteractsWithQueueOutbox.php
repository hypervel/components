<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use InvalidArgumentException;
use UnitEnum;

use function Hypervel\Support\enum_value;

trait InteractsWithQueueOutbox
{
    /**
     * Whether to stage the job through the transactional outbox.
     *
     * Leave uninitialized so ordinary jobs carry no additional serialized state.
     */
    public ?bool $viaOutbox;

    /**
     * The database connection that owns the staged job.
     */
    public ?string $outboxDatabase;

    /**
     * Dispatch the job through the transactional outbox.
     */
    public function viaOutbox(): static
    {
        $this->viaOutbox = true;

        return $this;
    }

    /**
     * Dispatch the job without the transactional outbox.
     */
    public function withoutOutbox(): static
    {
        $this->viaOutbox = false;
        $this->outboxDatabase = null;

        return $this;
    }

    /**
     * Stage the job on the given database connection.
     */
    public function onOutboxDatabase(UnitEnum|string $connection): static
    {
        $connection = (string) enum_value($connection);

        if ($connection === '') {
            throw new InvalidArgumentException('The outbox database connection must not be empty.');
        }

        $this->outboxDatabase = $connection;
        $this->viaOutbox = true;

        return $this;
    }
}
