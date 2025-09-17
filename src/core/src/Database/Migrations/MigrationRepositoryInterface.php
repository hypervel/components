<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Hyperf\Database\Migrations\MigrationRepositoryInterface as HyperfMigrationRepositoryInterface;

interface MigrationRepositoryInterface extends HyperfMigrationRepositoryInterface
{
    /**
     * Get the list of the migrations by batch number.
     */
    public function getMigrationsByBatch(int $batch): array;
}
