<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

interface MigrationRepositoryInterface
{
    /**
     * Get the completed migrations.
     *
     * @return string[]
     */
    public function getRan(): array;

    /**
     * Get the list of migrations.
     *
     * @return object{id: int, migration: string, batch: int}[]
     */
    public function getMigrations(int $steps): array;

    /**
     * Get the list of the migrations by batch.
     *
     * @return object{id: int, migration: string, batch: int}[]
     */
    public function getMigrationsByBatch(int $batch): array;

    /**
     * Get the last migration batch.
     *
     * @return object{id: int, migration: string, batch: int}[]
     */
    public function getLast(): array;

    /**
     * Get the completed migrations with their batch numbers.
     *
     * @return array<string, int>
     */
    public function getMigrationBatches(): array;

    /**
     * Log that a migration was run.
     */
    public function log(string $file, int $batch): void;

    /**
     * Remove a migration from the log.
     *
     * @param object{id?: int, migration: string, batch?: int} $migration
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
