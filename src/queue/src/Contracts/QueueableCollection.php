<?php

declare(strict_types=1);

namespace Hypervel\Queue\Contracts;

interface QueueableCollection
{
    /**
     * Get the type of the entities being queued.
     */
    public function getQueueableClass(): ?string;

    /**
     * Get the identifiers for all of the entities.
     *
     * @return array<int, mixed>
     */
    public function getQueueableIds(): array;

    /**
     * Get the relationships of the entities being queued.
     *
     * @return array<int, string>
     */
    public function getQueueableRelations(): array;

    /**
     * Get the connection of the entities being queued.
     */
    public function getQueueableConnection(): ?string;
}
