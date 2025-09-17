<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Hyperf\Database\Migrations\DatabaseMigrationRepository as HyperfDatabaseMigrationRepository;

class DatabaseMigrationRepository extends HyperfDatabaseMigrationRepository implements MigrationRepositoryInterface
{
    /**
     * Get the list of the migrations by batch number.
     */
    public function getMigrationsByBatch(int $batch): array
    {
        return $this->table()
            ->where('batch', $batch)
            ->orderBy('migration', 'desc')
            ->get()
            ->all();
    }
}
