<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Pennant\Database\Postgres;

use Hypervel\Database\QueryException;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Pennant\Database\DatabaseDriverTestCase;

#[RequiresDatabase('pgsql')]
class DatabaseDriverTest extends DatabaseDriverTestCase
{
    public function testRepeatedScopesCanBeActivatedTogether(): void
    {
        Feature::for(['tim', 'tim'])->activate('foo', 'on');
        Feature::flushCache();

        $this->assertSame('on', Feature::for('tim')->value('foo'));
    }

    public function testSerializationFailuresInsideARepeatableReadTransactionPropagate(): void
    {
        Feature::define('foo', fn () => 'candidate');
        $this->storeWinnerBeforeInsert('foo', 'tim', 'winner');

        try {
            DB::transaction(function () {
                DB::statement('set transaction isolation level repeatable read');

                Feature::for('tim')->value('foo');
            });
            $this->fail('The insert should have failed to serialize.');
        } catch (QueryException $exception) {
            $this->assertSame('40001', $exception->getCode());
        }
    }
}
