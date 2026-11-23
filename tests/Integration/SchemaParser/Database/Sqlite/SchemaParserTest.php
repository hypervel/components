<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\SchemaParser\Database\Sqlite;

use Hypervel\Filesystem\Filesystem;
use Hypervel\SchemaParser\Deferral;
use Hypervel\SchemaParser\SQLiteParser;
use Hypervel\Support\Facades\DB;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Integration\Database\Sqlite\SqliteTestCase;
use Symfony\Component\Process\Process;

class SchemaParserTest extends SqliteTestCase
{
    public function testNativeDumpMatchesTheDatabaseSchema(): void
    {
        if ($this->usesSqliteInMemoryDatabaseConnection()) {
            $this->markTestSkipped('Schema dumping requires a file-backed SQLite connection.');
        }

        $connection = DB::connection();
        $files = $this->app->make(Filesystem::class);
        $directory = ParallelTesting::tempDir('SchemaParserSqlite');
        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);
        $this->beforeApplicationDestroyed(static fn () => $files->deleteDirectory($directory));
        $migration = "schema_parser_'insert_marker";

        try {
            $connection->statement('CREATE TABLE parents (id integer primary key, name text)');
            $connection->statement(<<<'SQL'
                CREATE TABLE "records" (
                    id integer primary key autoincrement not null,
                    parent_id integer references parents(id) on delete set null on update cascade deferrable initially deferred,
                    title varchar   (255) default 'Untitled (draft)' not null,
                    amount numeric(12, 2) default (1 + 2),
                    label text COLLATE NOCASE,
                    code "INTEGER ",
                    doubled numeric as (amount * 2) stored,
                    CONSTRAINT positive_amount CHECK (amount >= 0),
                    UNIQUE (label, title)
                )
                SQL);
            $connection->statement('CREATE INDEX records_title_index ON records (title DESC, lower(label)) WHERE amount > 0');
            $connection->statement('CREATE TABLE tags (record_id integer, name text, primary key (record_id, name)) without rowid');
            $connection->statement('CREATE VIEW report AS SELECT id, title FROM records');
            $connection->statement('CREATE VIRTUAL TABLE search USING fts5(body)');
            $connection->statement("CREATE TRIGGER records_touch AFTER UPDATE ON records BEGIN UPDATE records SET title = 'x;y' WHERE id = new.id; END");
            $connection->table('migrations')->insert(['migration' => $migration, 'batch' => 0]);

            $path = $directory . '/schema.sql';
            $connection->getSchemaState(processFactory: static fn (string $command): Process => Process::fromShellCommandline($command)->setTimeout(30))
                ->dump($connection, $path);
            $sql = $files->get($path);
            $schema = (new SQLiteParser)->parse($sql);
            $builder = $connection->getSchemaBuilder();
            $table = $schema->table('records');
            $columns = array_column($builder->getColumns('records'), null, 'name');

            $this->assertSame([], $schema->issues);
            $this->assertEqualsCanonicalizing(array_column($builder->getTables(), 'name'), array_column($schema->tables, 'name'));
            $this->assertTrue($table->isComplete());
            $this->assertSame('main', $table->schema);
            $this->assertSame(array_keys($columns), array_column($table->columns, 'name'));
            foreach ($table->columns as $column) {
                $this->assertSame($columns[$column->name]['type_name'], $column->type->name, $column->name);
                $this->assertSame($columns[$column->name]['nullable'], $column->nullable, $column->name);
                $this->assertSame($columns[$column->name]['default'], $column->default, $column->name);
                $this->assertSame($columns[$column->name]['auto_increment'], $column->autoIncrement, $column->name);
                $this->assertSame($columns[$column->name]['generation']['type'] ?? null, $column->generation?->type->value, $column->name);
            }
            // Collations keep their declared spelling, which the framework lowercases.
            $this->assertSame('NOCASE', $table->column('label')->collation);
            $this->assertSame('nocase', $columns['label']['collation']);
            $this->assertSame(['12', '2'], $table->column('amount')->type->modifiers);

            $indexes = array_column($builder->getIndexes('records'), null, 'name');
            $this->assertSame($indexes['primary']['columns'], array_column($table->primaryKey()->columns, 'name'));
            $this->assertNull($table->indexes[1]->name);
            $this->assertSame($indexes['sqlite_autoindex_records_1']['columns'], array_column($table->indexes[1]->columns, 'name'));
            $this->assertSame($indexes['records_title_index']['key_columns'], array_column($table->indexes[2]->columns, 'name'));
            $this->assertTrue($table->indexes[2]->columns[0]->descending);
            $this->assertSame('amount > 0', $table->indexes[2]->where);

            $foreignKey = $builder->getForeignKeys('records')[0];
            $this->assertSame($foreignKey['columns'], $table->foreignKeys[0]->columns);
            $this->assertSame($foreignKey['foreign_schema'], $table->foreignKeys[0]->foreignTable->schema);
            $this->assertSame($foreignKey['foreign_table'], $table->foreignKeys[0]->foreignTable->name);
            $this->assertSame($foreignKey['foreign_columns'], $table->foreignKeys[0]->foreignColumns);
            $this->assertSame($foreignKey['on_update'], $table->foreignKeys[0]->onUpdate->value);
            $this->assertSame($foreignKey['on_delete'], $table->foreignKeys[0]->onDelete->value);
            // SQLite's metadata has no deferral, so the declaration is the oracle.
            $this->assertSame(Deferral::Deferred, $table->foreignKeys[0]->deferral);
            $this->assertSame('positive_amount', $table->checks[0]->name);

            // A rowid alias holds no NULL, although the framework reports the column as nullable without NOT NULL.
            $this->assertTrue(array_column($builder->getColumns('parents'), null, 'name')['id']['nullable']);
            $this->assertFalse($schema->table('parents')->column('id')->nullable);
            $this->assertTrue($schema->table('parents')->column('id')->autoIncrement);

            $tags = array_column($builder->getColumns('tags'), null, 'name');
            foreach ($schema->table('tags')->columns as $column) {
                $this->assertSame($tags[$column->name]['nullable'], $column->nullable, $column->name);
                $this->assertSame($tags[$column->name]['auto_increment'], $column->autoIncrement, $column->name);
            }

            $this->assertSame('SELECT id, title FROM records', $schema->view('report')->definition);
            $this->assertFalse($schema->table('search')->isComplete());
            $this->assertMatchesRegularExpression("/^INSERT INTO migrations VALUES\\(\\d+,'schema_parser_''insert_marker',0\\);$/m", $sql);
        } finally {
            $connection->statement('DROP VIEW IF EXISTS report');
            $connection->statement('DROP TRIGGER IF EXISTS records_touch');
            $connection->statement('DROP TABLE IF EXISTS search');
            $connection->statement('DROP TABLE IF EXISTS tags');
            $connection->statement('DROP TABLE IF EXISTS records');
            $connection->statement('DROP TABLE IF EXISTS parents');
            $connection->table('migrations')->where('migration', $migration)->delete();
        }
    }
}
