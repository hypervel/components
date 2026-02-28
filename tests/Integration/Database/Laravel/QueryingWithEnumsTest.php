<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

include_once 'Enums.php';

/**
 * @internal
 * @coversNothing
 */
class QueryingWithEnumsTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('enum_casts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('string_status', 100)->nullable();
            $table->integer('integer_status')->nullable();
            $table->string('non_backed_status', 100)->nullable();
        });
    }

    public function testCanQueryWithEnums()
    {
        DB::table('enum_casts')->insert([
            'string_status' => 'pending',
            'integer_status' => 1,
            'non_backed_status' => 'pending',
        ]);

        $record = DB::table('enum_casts')->where('string_status', StringStatus::pending)->first();
        $record2 = DB::table('enum_casts')->where('integer_status', IntegerStatus::pending)->first();
        $record3 = DB::table('enum_casts')->whereIn('integer_status', [IntegerStatus::pending])->first();
        $record4 = DB::table('enum_casts')->where('non_backed_status', NonBackedStatus::pending)->first();

        $this->assertNotNull($record);
        $this->assertNotNull($record2);
        $this->assertNotNull($record3);
        $this->assertNotNull($record4);
        $this->assertSame('pending', $record->string_status);
        $this->assertEquals(1, $record2->integer_status);
        $this->assertSame('pending', $record4->non_backed_status);
    }

    public function testCanInsertWithEnums()
    {
        DB::table('enum_casts')->insert([
            'string_status' => StringStatus::pending,
            'integer_status' => IntegerStatus::pending,
            'non_backed_status' => NonBackedStatus::pending,
        ]);

        $record = DB::table('enum_casts')->where('string_status', StringStatus::pending)->first();

        $this->assertNotNull($record);
        $this->assertSame('pending', $record->string_status);
        $this->assertEquals(1, $record->integer_status);
        $this->assertSame('pending', $record->non_backed_status);
    }
}
