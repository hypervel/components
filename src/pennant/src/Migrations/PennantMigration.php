<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Migrations;

use Hypervel\Database\Migrations\Migration;

abstract class PennantMigration extends Migration
{
    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        $connection = config('pennant.stores.database.connection');

        return ($connection === null || $connection === 'null') ? config('database.default') : $connection;
    }
}
