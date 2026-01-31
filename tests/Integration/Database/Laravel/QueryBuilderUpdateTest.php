<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once 'Enums.php';

/**
 * @internal
 * @coversNothing
 */
class QueryBuilderUpdateTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('example', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->integer('credits')->nullable();
            $table->json('payload')->nullable();
        });

        Schema::create('example_credits', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('example_id');
            $table->integer('credits');
        });
    }

    #[DataProvider('jsonValuesDataProvider')]
    #[RequiresDatabase(['sqlite', 'mysql', 'mariadb'])]
    public function testBasicUpdateForJson($column, $given, $expected)
    {
        DB::table('example')->insert([
            ['name' => 'Taylor Otwell', 'title' => 'Mr.'],
        ]);

        DB::table('example')->update([
            $column => $given,
        ]);

        $this->assertDatabaseHas('example', [
            'name' => 'Taylor Otwell',
            'title' => 'Mr.',
            $column => $column === 'payload' ? $this->castAsJson($expected) : $expected,
        ]);
    }

    public static function jsonValuesDataProvider()
    {
        yield ['payload', ['Laravel', 'Founder'], ['Laravel', 'Founder']];
        yield ['payload', collect(['Laravel', 'Founder']), ['Laravel', 'Founder']];
        yield ['status', StringStatus::draft, 'draft'];
    }

    #[RequiresDatabase(['sqlite', 'mysql', 'mariadb'])]
    public function testSubqueryUpdate()
    {
        DB::table('example')->insert([
            ['name' => 'Taylor Otwell', 'title' => 'Mr.'],
            ['name' => 'Tim MacDonald', 'title' => 'Mr.'],
        ]);

        DB::table('example_credits')->insert([
            ['example_id' => 1, 'credits' => 10],
            ['example_id' => 1, 'credits' => 20],
        ]);

        $this->assertDatabaseHas('example', [
            'name' => 'Taylor Otwell',
            'title' => 'Mr.',
            'credits' => null,
        ]);

        $this->assertDatabaseHas('example', [
            'name' => 'Tim MacDonald',
            'title' => 'Mr.',
            'credits' => null,
        ]);

        DB::table('example')->update([
            'credits' => DB::table('example_credits')->selectRaw('sum(credits)')->whereColumn('example_credits.example_id', 'example.id'),
        ]);

        $this->assertDatabaseHas('example', [
            'name' => 'Taylor Otwell',
            'title' => 'Mr.',
            'credits' => 30,
        ]);

        $this->assertDatabaseHas('example', [
            'name' => 'Tim MacDonald',
            'title' => 'Mr.',
            'credits' => null,
        ]);
    }
}
