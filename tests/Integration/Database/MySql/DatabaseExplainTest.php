<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MySql;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;

class DatabaseExplainTest extends MySqlTestCase
{
    /**
     * Create the table for query explanations.
     */
    protected function afterRefreshingDatabase(): void
    {
        if (! Schema::hasTable('db_explain_tbl')) {
            Schema::create('db_explain_tbl', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Remove the table for query explanations.
     */
    protected function destroyDatabaseMigrations(): void
    {
        Schema::dropIfExists('db_explain_tbl');
    }

    public function testResultIsAnObject(): void
    {
        DB::table('db_explain_tbl')->insert(['name' => 'taylor']);

        $result = DB::table('db_explain_tbl')->where('name', 'taylor')->explain();

        $this->assertIsObject($result);
        $this->assertSame(1, $result->count());
        $this->assertIsObject($result->first());
    }
}
