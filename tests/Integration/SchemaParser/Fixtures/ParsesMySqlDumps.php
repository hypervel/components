<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\SchemaParser\Fixtures;

use Hypervel\Filesystem\Filesystem;
use Hypervel\SchemaParser\GenerationType;
use Hypervel\SchemaParser\MariaDbParser;
use Hypervel\SchemaParser\MySqlParser;
use Hypervel\SchemaParser\ReferentialAction;
use Hypervel\Support\Facades\DB;
use Hypervel\Testing\ParallelTesting;
use Symfony\Component\Process\Process;

trait ParsesMySqlDumps
{
    public function testNativeDumpMatchesTheDatabaseSchema(): void
    {
        $connection = DB::connection();
        $files = $this->app->make(Filesystem::class);
        $directory = ParallelTesting::tempDir('SchemaParserMySql');
        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);
        $this->beforeApplicationDestroyed(static fn () => $files->deleteDirectory($directory));

        try {
            $connection->statement('CREATE TABLE parser_parents (id bigint unsigned AUTO_INCREMENT PRIMARY KEY)');
            $connection->statement(<<<'SQL'
                CREATE TABLE parser_children (
                    id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                    parent_id bigint unsigned,
                    state enum('open', 'it''s closed') NOT NULL DEFAULT 'open',
                    amount decimal(12, 2) NOT NULL DEFAULT -1.50,
                    label varchar(255) DEFAULT 'literal AUTO_INCREMENT=33' COMMENT 'comment AUTO_INCREMENT=44',
                    doubled decimal(12, 2) GENERATED ALWAYS AS (amount * 2) STORED,
                    KEY by_label (label(20)),
                    CONSTRAINT parser_parent FOREIGN KEY (parent_id) REFERENCES parser_parents (id)
                        ON UPDATE CASCADE ON DELETE SET NULL,
                    CONSTRAINT parser_amount CHECK (amount >= -10)
                ) ENGINE=InnoDB AUTO_INCREMENT=30 COMMENT='table AUTO_INCREMENT=45'
                SQL);
            $connection->statement('CREATE VIEW parser_report AS SELECT label FROM parser_children WHERE amount >= 0 WITH CASCADED CHECK OPTION');
            $connection->unprepared(<<<'SQL'
                CREATE PROCEDURE parser_create_table()
                BEGIN
                    SET @example = 'Client SQL example:
                DELIMITER ;
                ) ENGINE=InnoDB AUTO_INCREMENT=77
                ';
                    CREATE TABLE parser_routine_created (
                        id int AUTO_INCREMENT PRIMARY KEY
                    ) ENGINE=InnoDB AUTO_INCREMENT=50;
                END
                SQL);

            $path = $directory . '/schema.sql';
            $connection->getSchemaState(processFactory: static fn (string $command): Process => Process::fromShellCommandline($command)->setIdleTimeout(20))
                ->withMigrationTable(null)
                ->dump($connection, $path);

            $sql = $files->get($path);
            $schema = ($connection->isMaria() ? new MariaDbParser : new MySqlParser)->parse($sql);
            $table = $schema->table('parser_children');
            $columns = array_column($connection->getSchemaBuilder()->getColumns('parser_children'), null, 'name');

            $this->assertTrue($schema->isComplete());
            $this->assertSame(array_keys($columns), array_column($table->columns, 'name'));
            foreach ($table->columns as $column) {
                $this->assertSame($columns[$column->name]['type_name'], $column->type->name);
                $this->assertSame($columns[$column->name]['nullable'], $column->nullable);
                $this->assertSame($columns[$column->name]['auto_increment'], $column->autoIncrement);
                $this->assertSame($columns[$column->name]['comment'], $column->comment);
            }
            $this->assertSame(['open', "it's closed"], $table->column('state')->type->values);
            $this->assertSame(['12', '2'], $table->column('amount')->type->modifiers);
            $this->assertSame("'literal AUTO_INCREMENT=33'", $table->column('label')->default);
            $this->assertSame('table AUTO_INCREMENT=45', $table->comment);
            $this->assertSame(GenerationType::Stored, $table->column('doubled')->generation->type);
            $this->assertSame(['id'], array_column($table->primaryKey()->columns, 'name'));
            $index = array_column($table->indexes, null, 'name')['by_label'];
            $this->assertSame('label', $index->columns[0]->name);
            $this->assertSame(20, $index->columns[0]->length);
            $this->assertSame(['parent_id'], $table->foreignKeys[0]->columns);
            $this->assertSame('parser_parents', $table->foreignKeys[0]->foreignTable->name);
            $this->assertSame(['id'], $table->foreignKeys[0]->foreignColumns);
            $this->assertSame(ReferentialAction::Cascade, $table->foreignKeys[0]->onUpdate);
            $this->assertSame(ReferentialAction::SetNull, $table->foreignKeys[0]->onDelete);

            if ($connection->isMaria() || version_compare($connection->getServerVersion(), '8.0.16', '>=')) {
                $this->assertSame('parser_amount', $table->checks[0]->name);
                $this->assertTrue($table->checks[0]->enforced);
            }

            $this->assertNotNull($schema->view('parser_report'));
            $this->assertStringNotContainsString('CHECK OPTION', $schema->view('parser_report')->definition);
            $this->assertNull($schema->table('parser_report'));
            $this->assertNull($schema->table('parser_routine_created'));
            $this->assertStringNotContainsString('AUTO_INCREMENT=30', $sql);
            $this->assertStringContainsString('AUTO_INCREMENT=50', $sql);
            $this->assertStringContainsString('AUTO_INCREMENT=77', $sql);
        } finally {
            $connection->statement('DROP VIEW IF EXISTS parser_report');
            $connection->statement('DROP PROCEDURE IF EXISTS parser_create_table');
            $connection->statement('DROP TABLE IF EXISTS parser_children');
            $connection->statement('DROP TABLE IF EXISTS parser_parents');
        }
    }
}
