<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MySql;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresOperatingSystem('Linux|Darwin')]
#[RequiresPhpExtension('pdo_mysql')]
class DatabaseMySqlSchemaBuilderTest extends MySqlTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('database.connections.mysql_no_backslash_escapes', array_replace(
            $config->array('database.connections.mysql'),
            ['modes' => ['NO_BACKSLASH_ESCAPES']],
        ));
    }

    public function testAddCommentToTable(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->comment('This is a comment');
        });

        $tableInfo = DB::table('information_schema.tables')
            ->where('table_schema', $this->app->make('config')->string('database.connections.mysql.database'))
            ->where('table_name', 'users')
            ->select('table_comment as table_comment')
            ->first();

        $this->assertSame('This is a comment', $tableInfo->table_comment);

        Schema::drop('users');
    }

    #[DataProvider('schemaLiteralConnections')]
    public function testSchemaLiteralsPreserveQuotesAndBackslashes(string $connectionName): void
    {
        $connection = DB::connection($connectionName);
        $schema = $connection->getSchemaBuilder();
        $value = "O'Brien\\User";
        $schema->create('quoted_values', function (Blueprint $table) use ($value): void {
            $table->integer('id');
            $table->enum('role', [$value])->default($value)->comment($value);
            $table->comment($value);
        });

        $connection->table('quoted_values')->insert(['id' => 1]);

        $this->assertSame($value, $connection->table('quoted_values')->value('role'));
        $this->assertSame($value, collect($schema->getColumns('quoted_values'))->firstWhere('name', 'role')['comment']);
        $this->assertSame($value, collect($schema->getTables())->firstWhere('name', 'quoted_values')['comment']);
    }

    /**
     * Provide connections with each MySQL backslash escaping mode.
     *
     * @return list<array{string}>
     */
    public static function schemaLiteralConnections(): array
    {
        return [['mysql'], ['mysql_no_backslash_escapes']];
    }

    #[RequiresDatabase('mysql', '>=8.0.13')]
    public function testGetRawIndex()
    {
        Schema::create('table', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->rawIndex('(year(created_at))', 'table_raw_index');
        });

        $indexes = Schema::getIndexes('table');

        $this->assertSame([], collect($indexes)->firstWhere('name', 'table_raw_index')['columns']);
    }

    public function testWithoutForeignKeyConstraintsPreservesIncomingStateAndNests(): void
    {
        $connection = DB::connection();
        $outer = $connection->getSchemaBuilder();
        $inner = $connection->getSchemaBuilder();

        try {
            $outer->enableForeignKeyConstraints();

            $outer->withoutForeignKeyConstraints(function () use ($connection, $inner): void {
                $this->assertSame(0, (int) $connection->scalar('select @@foreign_key_checks'));

                $inner->withoutForeignKeyConstraints(function () use ($connection): void {
                    $this->assertSame(0, (int) $connection->scalar('select @@foreign_key_checks'));
                });

                $this->assertSame(0, (int) $connection->scalar('select @@foreign_key_checks'));
            });

            $this->assertSame(1, (int) $connection->scalar('select @@foreign_key_checks'));

            $outer->disableForeignKeyConstraints();
            $outer->withoutForeignKeyConstraints(function () use ($connection): void {
                $this->assertSame(0, (int) $connection->scalar('select @@foreign_key_checks'));
            });

            $this->assertSame(0, (int) $connection->scalar('select @@foreign_key_checks'));
        } finally {
            $outer->enableForeignKeyConstraints();
        }
    }
}
