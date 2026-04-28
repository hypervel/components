<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

interface MigrationRepositoryInterface
{
    /**
     * Get the completed migrations.
     */
    public function getRan(): array;

    /**
     * Get the list of migrations.
     */
    public function getMigrations(int $steps): array;

    /**
     * Get the list of the migrations by batch.
     */
    public function getMigrationsByBatch(int $batch): array;

    /**
     * Get the last migration batch.
     */
    public function getLast(): array;

    /**
     * Get the completed migrations with their batch numbers.
     */
    public function getMigrationBatches(): array;

    /**
     * Log that a migration was run.
     */
    public function log(string $file, int $batch): void;

    /**
     * Remove a migration from the log.
     */
    public function delete(object $migration): void;

    /**
     * Get the next migration batch number.
     */
    public function getNextBatchNumber(): int;

    /**
     * Create the migration repository data store.
     */
    public function createRepository(): void;

    /**
     * Determine if the migration repository exists.
     */
    public function repositoryExists(): bool;

    /**
     * Delete the migration repository data store.
     */
    public function deleteRepository(): void;

    /**
     * Set the information source to gather data.
     */
    public function setSource(?string $name): void;
}
