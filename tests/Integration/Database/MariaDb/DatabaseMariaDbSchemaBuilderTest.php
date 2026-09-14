<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MariaDb;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresOperatingSystem('Linux|Darwin')]
#[RequiresPhpExtension('pdo_mysql')]
class DatabaseMariaDbSchemaBuilderTest extends MariaDbTestCase
{
    public function testAddCommentToTable(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->comment('This is a comment');
        });

        $tableInfo = DB::table('information_schema.tables')
            ->where('table_schema', $this->app->make('config')->string('database.connections.mariadb.database'))
            ->where('table_name', 'users')
            ->select('table_comment as table_comment')
            ->first();

        $this->assertEquals('This is a comment', $tableInfo->table_comment);

        Schema::drop('users');
    }

    public function testJsonPathEscapingPreservesStoredValues(): void
    {
        $key = 'App\Models\User';
        Schema::create('quoted_values', function (Blueprint $table) use ($key): void {
            $table->json('options');
            $table->string('label')->virtualAsJson('options->' . $key);
        });

        DB::table('quoted_values')->insert(['options' => json_encode([$key => 'before'], JSON_THROW_ON_ERROR)]);
        $this->assertSame('before', DB::table('quoted_values')->value('label'));
        $this->assertSame('before', DB::table('quoted_values')->value('options->' . $key));

        $this->assertSame(1, DB::table('quoted_values')->update(['options->a"b' => 'after']));
        $this->assertSame(
            [$key => 'before', 'a"b' => 'after'],
            json_decode(DB::table('quoted_values')->value('options'), true, flags: JSON_THROW_ON_ERROR),
        );
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
