<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use PDO;

class DatabaseSchemaBuilderTest extends SqliteTestCase
{
    protected function setUpInCoroutine(): void
    {
        // Clean up all connections before each test
        Schema::dropAllTables();
        $this->artisan('migrate:install');
        Schema::connection('sqlite-with-prefix')->dropAllTables();
        $this->artisan('migrate:install', ['--database' => 'sqlite-with-prefix']);
        Schema::connection('sqlite-with-indexed-prefix')->dropAllTables();
        $this->artisan('migrate:install', ['--database' => 'sqlite-with-indexed-prefix']);
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set([
            'database.connections.sqlite-with-prefix' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => 'example-.',
                'prefix_indexes' => false,
            ],
            'database.connections.sqlite-with-indexed-prefix' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => 'example_',
                'prefix_indexes' => true,
            ],
        ]);
    }

    public function testDropAllTablesWorksWithForeignKeys(): void
    {
        Schema::create('table1', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name');
        });

        Schema::create('table2', function (Blueprint $table) {
            $table->integer('id');
            $table->string('user_id');
            $table->foreign('user_id')->references('id')->on('table1');
        });

        $this->assertTrue(Schema::hasTable('table1'));
        $this->assertTrue(Schema::hasTable('table2'));

        Schema::dropAllTables();

        $this->assertFalse(Schema::hasTable('table1'));
        $this->assertFalse(Schema::hasTable('table2'));

        // Restore migrations table for teardown's migrate:rollback
        $this->artisan('migrate:install');
    }

    public function testCreateMigrationRepositoryTablePreservesTheRelationalSchemaAndPrefix(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', 'audit_');
        $builder = $connection->getSchemaBuilder();

        $builder->createMigrationRepositoryTable('migrations');

        $columns = $builder->getColumns('migrations');

        $this->assertSame(['id', 'migration', 'batch'], array_column($columns, 'name'));
        $this->assertTrue($columns[0]['auto_increment']);
        $this->assertSame(['integer', 'varchar', 'integer'], array_column($columns, 'type_name'));
        $this->assertSame([false, false, false], array_column($columns, 'nullable'));
        $this->assertSame(['audit_migrations'], $builder->getTableListing(schemaQualified: false));

        $connection->table('migrations')->insert(['migration' => 'create_users', 'batch' => 1]);

        $this->assertSame(1, $connection->table('migrations')->value('id'));
    }

    public function testTruncateTablesClearsWriteRowsWhenTheReadDatabaseIsEmpty(): void
    {
        $write = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['sticky' => false]);
        $read = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');

        foreach ([$write, $read] as $connection) {
            $connection->getSchemaBuilder()->create('widgets', function (Blueprint $table): void {
                $table->id();
            });
        }

        $write->table('widgets')->insert(['id' => 1]);
        $write->setReadPdo($read->getPdo());

        $this->assertFalse($write->table('widgets')->exists());
        $this->assertTrue($write->table('widgets')->useWritePdo()->exists());

        $write->getSchemaBuilder()->truncateTables(['main.widgets']);

        $this->assertSame(0, $write->table('widgets')->useWritePdo()->count());
    }

    public function testTruncateTablesPreservesThePrefixAndSkipsEmptyTables(): void
    {
        $connection = DB::connection('sqlite-with-indexed-prefix');
        $schema = $connection->getSchemaBuilder();

        foreach (['populated', 'empty'] as $table) {
            $schema->create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
            });
            $connection->table($table)->insert(['id' => 1]);
        }

        $connection->table('empty')->delete();

        $schema->truncateTables(['populated', 'empty']);

        $this->assertSame(0, $connection->table('populated')->count());
        $this->assertSame(0, $connection->table('empty')->count());
        $this->assertSame(1, $connection->table('populated')->insertGetId(['id' => null]));
        $this->assertSame(2, $connection->table('empty')->insertGetId(['id' => null]));
    }

    public function testHasColumnAndIndexWithPrefixIndexDisabled(): void
    {
        $connection = DB::connection('sqlite-with-prefix');

        Schema::connection('sqlite-with-prefix')->create('table1', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name')->index();
        });

        $indexes = array_column($connection->getSchemaBuilder()->getIndexes('table1'), 'name');

        $this->assertContains('table1_name_index', $indexes, 'name');
    }

    public function testHasColumnAndIndexWithPrefixIndexEnabled(): void
    {
        $connection = DB::connection('sqlite-with-indexed-prefix');

        Schema::connection('sqlite-with-indexed-prefix')->create('table1', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name')->index();
        });

        $indexes = array_column($connection->getSchemaBuilder()->getIndexes('table1'), 'name');

        $this->assertContains('example_table1_name_index', $indexes);
    }

    public function testSchemaQualifiedPrefixedTablesPreserveQueryIdentifiers(): void
    {
        $connection = DB::connection('sqlite-with-indexed-prefix');
        $connection->getSchemaBuilder()->create('items', function (Blueprint $table): void {
            $table->integer('id');
        });
        $connection->table('items')->insert([['id' => 1], ['id' => 2]]);

        $this->assertSame([
            ['id' => 1, 'bonus' => 42],
            ['id' => 2, 'bonus' => 42],
        ], $connection->table('main.items', 'source')->addSelect(['bonus' => $connection->query()->selectRaw('42')])
            ->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all());

        $query = $connection->table('main.items', 'source')
            ->join('items as joined', 'joined.id', '=', 'source.id')
            ->groupBy('source.id');

        $this->assertSame(2, $query->getCountForPagination());
        $this->assertNull($query->columns);
        $this->assertSame(1, $connection->table('main.items')->delete(1));
        $this->assertSame([2], $connection->table('items')->pluck('id')->all());
    }

    public function testAlterTableAddForeignKeyWithPrefix(): void
    {
        $schema = Schema::connection('sqlite-with-prefix');

        $schema->create('table1', function (Blueprint $table) {
            $table->id();
        });

        $schema->create('table2', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('table1');
        });

        $schema->table('table2', function (Blueprint $table) {
            $table->foreignId('moderator_id')->constrained('table1');
        });

        $foreignKeys = collect($schema->getForeignKeys('table2'));

        $this->assertTrue(
            $foreignKeys->contains(
                fn ($fk) => $fk['foreign_table'] === 'example-.table1'
                && $fk['foreign_columns'] === ['id']
                && $fk['columns'] === ['author_id']
            )
        );

        $this->assertTrue(
            $foreignKeys->contains(
                fn ($fk) => $fk['foreign_table'] === 'example-.table1'
                && $fk['foreign_columns'] === ['id']
                && $fk['columns'] === ['moderator_id']
            )
        );
    }

    public function testAlterTableAddForeignKeyWithExpressionDefault(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->json('flags')->default(new Expression('(JSON_ARRAY())'));
        });

        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->constrained('items');
        });

        $this->assertTrue(collect(Schema::getForeignKeys('items'))->contains(
            fn ($fk) => $fk['foreign_table'] === 'items'
                && $fk['foreign_columns'] === ['id']
                && $fk['columns'] === ['item_id']
        ));

        $columns = Schema::getColumns('items');

        $this->assertTrue(collect($columns)->contains(
            fn ($column) => $column['name'] === 'flags' && $column['default'] === 'JSON_ARRAY()'
        ));

        $this->assertTrue(collect($columns)->contains(fn ($column) => $column['name'] === 'item_id' && $column['nullable']));
    }
}
