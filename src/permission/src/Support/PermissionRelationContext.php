<?php

declare(strict_types=1);

namespace Hypervel\Permission\Support;

final readonly class PermissionRelationContext
{
    /**
     * Create a permission relation context snapshot.
     */
    public function __construct(
        public ?PermissionPartition $partition,
        public bool $teamScoped,
        public int|string|null $team,
    ) {
    }

    /**
     * Build a collision-safe identity for this captured context.
     */
    public function identity(): string
    {
        $partition = $this->partition === null
            ? PermissionPartition::encodeCacheSegment(null)
            : $this->partition->cacheSegment();

        return $partition
            . ':' . PermissionPartition::encodeCacheSegment((int) $this->teamScoped)
            . ':' . PermissionPartition::encodeCacheSegment($this->teamScoped ? $this->team : null);
    }
}
