<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Database\Fixtures\Enums\StringStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

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

    #[RequiresDatabase(['sqlite', 'pgsql'])]
    #[TestWith([false])]
    #[TestWith([true])]
    public function testOffsetOnlyWritesPreserveTheExcludedRows(bool $delete): void
    {
        DB::table('example')->insert([
            ['name' => 'Keep', 'credits' => 30],
            ['name' => 'Last', 'credits' => 10],
            ['name' => 'Middle', 'credits' => 20],
        ]);

        $query = DB::table('example')->orderByDesc('credits')->offset(1);
        $this->assertSame(2, $delete ? $query->delete() : $query->update(['status' => 'changed']));
        $this->assertDatabaseHas('example', ['name' => 'Keep', 'status' => null]);

        if ($delete) {
            $this->assertSame(['Keep'], DB::table('example')->pluck('name')->all());
        } else {
            $this->assertSame(['Last', 'Middle'], DB::table('example')->where('status', 'changed')->orderBy('id')->pluck('name')->all());
        }
    }

    #[DataProvider('jsonValuesDataProvider')]
    #[RequiresDatabase(['sqlite', 'mysql', 'mariadb'])]
    public function testBasicUpdateForJson($column, $given, $expected): void
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
        yield ['payload', ['Hypervel', 'Founder'], ['Hypervel', 'Founder']];
        yield ['payload', collect(['Hypervel', 'Founder']), ['Hypervel', 'Founder']];
        yield ['status', StringStatus::Draft, 'draft'];
    }

    #[RequiresDatabase(['sqlite', 'mysql', 'mariadb'])]
    public function testSubqueryUpdate(): void
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
