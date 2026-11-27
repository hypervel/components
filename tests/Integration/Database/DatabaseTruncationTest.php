<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;

class DatabaseTruncationTest extends DatabaseTestCase
{
    /**
     * Create the tables used to verify truncation.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->string('body');
        });
    }

    public function testTruncateTables(): void
    {
        DB::table('posts')->insert([['title' => 'Hypervel'], ['title' => 'Hypervel']]);
        DB::table('comments')->insert(['body' => 'Hypervel']);
        DB::table('comments')->delete();

        (new class($this->app) {
            use DatabaseTruncation {
                truncateTablesForAllConnections as public;
            }

            /**
             * Create the truncation test harness.
             */
            public function __construct(public Application $app)
            {
            }
        })->truncateTablesForAllConnections();

        $this->assertSame(1, DB::table('posts')->insertGetId(['title' => 'Hypervel']));
        $this->assertSame(2, DB::table('comments')->insertGetId(['body' => 'Hypervel']));
    }
}
