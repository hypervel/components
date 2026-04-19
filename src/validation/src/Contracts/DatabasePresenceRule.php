<?php

declare(strict_types=1);

namespace Hypervel\Validation\Contracts;

/**
 * Marker interface for database presence rules (exists/unique).
 *
 * Implemented by rule objects that carry database presence metadata
 * (table, column, wheres, etc.) for use by the batch optimization
 * and presence verification systems.
 *
 * @internal Not part of the public API. Intended for Hypervel's own
 *           Exists and Unique rule classes only.
 */
interface DatabasePresenceRule
{
    /**
     * Get the database presence rule metadata.
     *
     * @return array{table: string, column: string, wheres: array<int, array<string, mixed>>, using: array<int, mixed>, ignore?: mixed, idColumn?: string}
     */
    public function presenceMetadata(): array;
}
