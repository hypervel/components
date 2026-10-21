<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Pennant\Database\MySql;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Pennant\Database\MySqlFamilyDatabaseDriverTestCase;

#[RequiresDatabase('mysql')]
class DatabaseDriverTest extends MySqlFamilyDatabaseDriverTestCase
{
    public function testConcurrentWinnerIsReturnedInsideARepeatableReadTransaction(): void
    {
        Feature::define('foo', fn () => 'candidate');
        DB::statement('set session transaction isolation level repeatable read');
        $this->storeWinnerBeforeInsert('foo', 'tim', 'winner');

        $this->assertSame('winner', DB::transaction(fn () => Feature::for('tim')->value('foo')));
    }

    #[DefineEnvironment('usingTheUpsertAlias')]
    #[RequiresDatabase('mysql', '>=8.0.19')]
    public function testConcurrentWinnerIsReturnedWithTheUpsertAlias(): void
    {
        Feature::define('foo', fn () => 'candidate');
        $this->storeWinnerBeforeInsert('foo', 'tim', 'winner');

        $this->assertSame('winner', Feature::for('tim')->value('foo'));
    }

    /**
     * Compile upserts with a row alias instead of VALUES().
     */
    protected function usingTheUpsertAlias(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('database.connections.' . $config->string('database.default') . '.use_upsert_alias', true);
    }
}
