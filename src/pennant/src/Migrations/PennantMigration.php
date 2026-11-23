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
        $connection = config()->nullableString('pennant.stores.database.connection', null);

        // Without a connection of its own, the migration runs on the migrator's connection, so --database applies.
        return $connection === '' ? null : $connection;
    }
}
