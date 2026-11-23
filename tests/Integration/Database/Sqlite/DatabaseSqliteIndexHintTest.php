<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseSqliteIndexHintTest extends SqliteTestCase
{
    /**
     * Create the posts table and its rows.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->index();
            $table->integer('status')->index();
        });

        DB::table('posts')->insert([
            ['user_id' => 1, 'status' => 1],
            ['user_id' => 2, 'status' => 1],
        ]);
    }

    /**
     * Drop the posts table.
     */
    protected function destroyDatabaseMigrations(): void
    {
        Schema::drop('posts');
    }

    #[DataProvider('forceIndexDataProvider')]
    public function testForceIndex(string $index): void
    {
        $this->assertSame(1, DB::table('posts')->forceIndex($index)->where('user_id', 1)->count());
    }

    /**
     * Provide index hints that SQLite can run.
     */
    public static function forceIndexDataProvider(): array
    {
        return [
            'named index' => ['posts_user_id_index'],
            'primary key' => ['PRIMARY'],
            'multiple indexes' => ['posts_user_id_index, posts_status_index'],
        ];
    }
}
