<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Migrations\MigrationRepositoryInterface;
use Hyperf\Database\Migrations\Migrator as HyperfMigrator;
use Hyperf\Support\Filesystem\Filesystem;
use Hypervel\Database\Migrations\MigrationRepositoryInterface as HypervelMigrationRepositoryInterface;

class Migrator extends HyperfMigrator
{
    /**
     * Create a new migrator instance.
     */
    public function __construct(
        protected MigrationRepositoryInterface $repository,
        protected ConnectionResolverInterface $resolver,
        protected Filesystem $files,
        protected HypervelMigrationRepositoryInterface $hypervelRepository,
    ) {
    }

    /**
     * Get the migrations for a rollback operation.
     */
    protected function getMigrationsForRollback(array $options): array
    {
        if (($steps = $options['step'] ?? 0) > 0) {
            return $this->repository->getMigrations($steps);
        }

        if (($batch = $options['batch'] ?? 0) > 0) {
            return $this->hypervelRepository->getMigrationsByBatch($batch);
        }

        return $this->repository->getLast();
    }
}
