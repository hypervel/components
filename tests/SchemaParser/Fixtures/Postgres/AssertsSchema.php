<?php

declare(strict_types=1);

namespace Hypervel\Tests\SchemaParser\Fixtures\Postgres;

use Hypervel\SchemaParser\Column;
use Hypervel\SchemaParser\ColumnType;
use Hypervel\SchemaParser\Schema;
use Hypervel\SchemaParser\Table;

use function sprintf;

/**
 * Look-ups that fail the test rather than returning null.
 *
 * Besides removing a nullsafe operator from every assertion, this reports a
 * missing table or column as itself instead of as a confusing null comparison
 * further down.
 */
trait AssertsSchema
{
    /**
     * Find a required table.
     */
    private static function assertTable(Schema $database, string $name, ?string $schema = null): Table
    {
        $table = $database->table($name, $schema);

        self::assertNotNull($table, sprintf('Table "%s" was not found.', $name));

        return $table;
    }

    /**
     * Find a required column.
     */
    private static function assertColumn(Schema $database, string $table, string $column): Column
    {
        $found = self::assertTable($database, $table)->column($column);

        self::assertNotNull($found, sprintf('Column "%s.%s" was not found.', $table, $column));

        return $found;
    }

    /**
     * Find a required column type.
     */
    private static function assertColumnType(Schema $database, string $table, string $column): ColumnType
    {
        return self::assertColumn($database, $table, $column)->type;
    }
}
