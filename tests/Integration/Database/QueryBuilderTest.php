<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Pagination\LengthAwarePaginator;
use Hypervel\Database\MultipleRecordsFoundException;
use Hypervel\Database\RecordsNotFoundException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testing\Assert as PHPUnit;
use PDO;
use PDOException;
use RuntimeException;

class QueryBuilderTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->text('content');
            $table->timestamp('created_at');
        });

        DB::table('posts')->insert([
            ['title' => 'Foo Post', 'content' => 'Lorem Ipsum.', 'created_at' => new CarbonImmutable('2017-11-12 13:14:15')],
            ['title' => 'Bar Post', 'content' => 'Lorem Ipsum.', 'created_at' => new CarbonImmutable('2018-01-02 03:04:05')],
        ]);
    }

    public function testIncrement(): void
    {
        Schema::create('accounting', function (Blueprint $table) {
            $table->increments('id');
            $table->float('wallet_1');
            $table->float('wallet_2');
            $table->integer('user_id');
            $table->string('name');
        });

        DB::table('accounting')->insert([
            [
                'wallet_1' => 100,
                'wallet_2' => 200,
                'user_id' => 1,
                'name' => 'Taylor',
            ],
            [
                'wallet_1' => 15,
                'wallet_2' => 300,
                'user_id' => 2,
                'name' => 'Otwell',
            ],
        ]);
        $connection = DB::table('accounting')->getConnection();
        $connection->enableQueryLog();

        DB::table('accounting')->where('user_id', 2)->incrementEach([
            'wallet_1' => 10,
            'wallet_2' => -20,
        ], ['name' => 'foo']);

        $queryLogs = $connection->getQueryLog();
        $this->assertCount(1, $queryLogs);

        $rows = DB::table('accounting')->get();

        $this->assertCount(2, $rows);
        // other rows are not affected.
        $this->assertEquals([
            'id' => 1,
            'wallet_1' => 100,
            'wallet_2' => 200,
            'user_id' => 1,
            'name' => 'Taylor',
        ], (array) $rows[0]);

        $this->assertEquals([
            'id' => 2,
            'wallet_1' => 15 + 10,
            'wallet_2' => 300 - 20,
            'user_id' => 2,
            'name' => 'foo',
        ], (array) $rows[1]);

        // without the second argument.
        $affectedRowsCount = DB::table('accounting')->where('user_id', 2)->incrementEach([
            'wallet_1' => 20,
            'wallet_2' => 20,
        ]);

        $this->assertEquals(1, $affectedRowsCount);

        $rows = DB::table('accounting')->get();

        $this->assertEquals([
            'id' => 2,
            'wallet_1' => 15 + (10 + 20),
            'wallet_2' => 300 + (-20 + 20),
            'user_id' => 2,
            'name' => 'foo',
        ], (array) $rows[1]);

        // Test Can affect multiple rows at once.
        $affectedRowsCount = DB::table('accounting')->incrementEach([
            'wallet_1' => 31.5,
            'wallet_2' => '-32.5',
        ]);

        $this->assertEquals(2, $affectedRowsCount);

        $rows = DB::table('accounting')->get();
        $this->assertEquals([
            'id' => 1,
            'wallet_1' => 100 + 31.5,
            'wallet_2' => 200 - 32.5,
            'user_id' => 1,
            'name' => 'Taylor',
        ], (array) $rows[0]);

        $this->assertEquals([
            'id' => 2,
            'wallet_1' => 15 + (10 + 20 + 31.5),
            'wallet_2' => 300 + (-20 + 20 - 32.5),
            'user_id' => 2,
            'name' => 'foo',
        ], (array) $rows[1]);

        // In case of a conflict, the second argument wins and sets a fixed value:
        $affectedRowsCount = DB::table('accounting')->incrementEach([
            'wallet_1' => 3000,
        ], ['wallet_1' => 1.5]);

        $this->assertEquals(2, $affectedRowsCount);

        $rows = DB::table('accounting')->get();

        $this->assertEquals(1.5, $rows[0]->wallet_1);
        $this->assertEquals(1.5, $rows[1]->wallet_1);

        // Decrement accepts integer, float, and numeric-string amounts.
        $affectedRowsCount = DB::table('accounting')->decrementEach([
            'wallet_1' => 1,
            'wallet_2' => 0.5,
            'user_id' => '1',
        ]);

        $this->assertEquals(2, $affectedRowsCount);

        $rows = DB::table('accounting')->get();

        $this->assertEquals([
            'id' => 1,
            'wallet_1' => 0.5,
            'wallet_2' => 167,
            'user_id' => 0,
            'name' => 'Taylor',
        ], (array) $rows[0]);

        $this->assertEquals([
            'id' => 2,
            'wallet_1' => 0.5,
            'wallet_2' => 267,
            'user_id' => 1,
            'name' => 'foo',
        ], (array) $rows[1]);

        Schema::drop('accounting');
    }

    public function testSole()
    {
        $expected = ['id' => '1', 'title' => 'Foo Post'];

        $this->assertEquals($expected, (array) DB::table('posts')->where('title', 'Foo Post')->select('id', 'title')->sole());
    }

    public function testSoleWithParameters()
    {
        $expected = ['id' => '1'];

        $this->assertEquals($expected, (array) DB::table('posts')->where('title', 'Foo Post')->sole('id'));
        $this->assertEquals($expected, (array) DB::table('posts')->where('title', 'Foo Post')->sole(['id']));

        $expected = ['id' => '1', 'title' => 'Foo Post'];
        $this->assertEquals($expected, (array) DB::table('posts')->where('title', 'Foo Post')->sole(['id', 'title']));
    }

    public function testSoleFailsForMultipleRecords()
    {
        DB::table('posts')->insert([
            ['title' => 'Foo Post', 'content' => 'Lorem Ipsum.', 'created_at' => new CarbonImmutable('2017-11-12 13:14:15')],
        ]);

        $this->expectExceptionObject(new MultipleRecordsFoundException(2));

        DB::table('posts')->where('title', 'Foo Post')->sole();
    }

    public function testSoleFailsIfNoRecords()
    {
        $this->expectException(RecordsNotFoundException::class);

        DB::table('posts')->where('title', 'Baz Post')->sole();
    }

    public function testSelect()
    {
        $expected = ['id' => '1', 'title' => 'Foo Post'];

        $this->assertEquals($expected, (array) DB::table('posts')->select('id', 'title')->first());
        $this->assertEquals($expected, (array) DB::table('posts')->select(['id', 'title'])->first());

        $this->assertCount(4, (array) DB::table('posts')->select()->first());
    }

    public function testSelectReplacesExistingSelects()
    {
        $this->assertEquals(
            ['id' => '1', 'title' => 'Foo Post'],
            (array) DB::table('posts')->select('content')->select(['id', 'title'])->first()
        );
    }

    public function testSelectWithSubQuery()
    {
        $this->assertEquals(
            ['id' => '1', 'title' => 'Foo Post', 'foo' => 'Lorem Ipsum.'],
            (array) DB::table('posts')->select(['id', 'title', 'foo' => function ($query) {
                $query->select('content');
            }])->first()
        );
    }

    public function testAddSelect()
    {
        $expected = ['id' => '1', 'title' => 'Foo Post', 'content' => 'Lorem Ipsum.'];

        $this->assertEquals($expected, (array) DB::table('posts')->select('id')->addSelect('title', 'content')->first());
        $this->assertEquals($expected, (array) DB::table('posts')->select('id')->addSelect(['title', 'content'])->first());
        $this->assertEquals($expected, (array) DB::table('posts')->addSelect(['id', 'title', 'content'])->first());

        $this->assertCount(4, (array) DB::table('posts')->addSelect([])->first());
        $this->assertEquals(['id' => '1'], (array) DB::table('posts')->select('id')->addSelect([])->first());
    }

    public function testAddSelectWithSubQuery()
    {
        $this->assertEquals(
            ['id' => '1', 'title' => 'Foo Post', 'foo' => 'Lorem Ipsum.'],
            (array) DB::table('posts')->addSelect(['id', 'title', 'foo' => function ($query) {
                $query->select('content');
            }])->first()
        );
    }

    public function testFromWithAlias()
    {
        $this->assertCount(2, DB::table('posts', 'alias')->select('alias.*')->get());
    }

    public function testFromWithSubQuery()
    {
        $this->assertSame(
            'Fake Post',
            DB::table(function ($query) {
                $query->selectRaw("'Fake Post' as title");
            }, 'posts')->first()->title
        );
    }

    public function testWhereValueSubQuery()
    {
        $subQuery = function ($query) {
            $query->selectRaw("'Sub query value'");
        };

        $this->assertTrue(DB::table('posts')->where($subQuery, 'Sub query value')->exists());
        $this->assertFalse(DB::table('posts')->where($subQuery, 'Does not match')->exists());
        $this->assertTrue(DB::table('posts')->where($subQuery, '!=', 'Does not match')->exists());
    }

    public function testWhereValueSubQueryBuilder()
    {
        $subQuery = DB::table('posts')->selectRaw("'Sub query value'")->limit(1);

        $this->assertTrue(DB::table('posts')->where($subQuery, 'Sub query value')->exists());
        $this->assertFalse(DB::table('posts')->where($subQuery, 'Does not match')->exists());
        $this->assertTrue(DB::table('posts')->where($subQuery, '!=', 'Does not match')->exists());

        $this->assertTrue(DB::table('posts')->where(DB::raw('\'Sub query value\''), $subQuery)->exists());
        $this->assertFalse(DB::table('posts')->where(DB::raw('\'Does not match\''), $subQuery)->exists());
        $this->assertTrue(DB::table('posts')->where(DB::raw('\'Does not match\''), '!=', $subQuery)->exists());
    }

    public function testWhereNot()
    {
        $results = DB::table('posts')->whereNot(function ($query) {
            $query->where('title', 'Foo Post');
        })->get();

        $this->assertCount(1, $results);
        $this->assertSame('Bar Post', $results[0]->title);
    }

    public function testWhereNotInputStringParameter()
    {
        $results = DB::table('posts')->whereNot('title', 'Foo Post')->get();

        $this->assertCount(1, $results);
        $this->assertSame('Bar Post', $results[0]->title);

        DB::table('posts')->insert([
            ['title' => 'Baz Post', 'content' => 'Lorem Ipsum.', 'created_at' => new CarbonImmutable('2017-11-12 13:14:15')],
        ]);

        $results = DB::table('posts')->whereNot('title', 'Foo Post')->whereNot('title', 'Bar Post')->get();
        $this->assertSame('Baz Post', $results[0]->title);
    }

    public function testOrWhereNot()
    {
        $results = DB::table('posts')->where('id', 1)->orWhereNot(function ($query) {
            $query->where('title', 'Foo Post');
        })->get();

        $this->assertCount(2, $results);
    }

    public function testWhereDate()
    {
        $this->assertSame(1, DB::table('posts')->whereDate('created_at', '2018-01-02')->count());
        $this->assertSame(1, DB::table('posts')->whereDate('created_at', new CarbonImmutable('2018-01-02'))->count());
    }

    #[DefineEnvironment('defineEnvironmentWouldThrowsPDOException')]
    public function testWhereDateWithInvalidOperator()
    {
        $sql = DB::table('posts')->whereDate('created_at', '? OR 1=1', '2018-01-02');

        PHPUnit::assertArraySubset([
            [
                'column' => 'created_at',
                'type' => 'Date',
                'value' => '? OR 1=1',
                'boolean' => 'and',
            ],
        ], $sql->wheres);

        $this->assertSame(0, $sql->count());
    }

    public function testOrWhereDate()
    {
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereDate('created_at', '2018-01-02')->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereDate('created_at', new CarbonImmutable('2018-01-02'))->count());
    }

    #[DefineEnvironment('defineEnvironmentWouldThrowsPDOException')]
    public function testOrWhereDateWithInvalidOperator()
    {
        $sql = DB::table('posts')->where('id', 1)->orWhereDate('created_at', '? OR 1=1', '2018-01-02');

        PHPUnit::assertArraySubset([
            [
                'column' => 'id',
                'type' => 'Basic',
                'value' => 1,
                'boolean' => 'and',
            ],
            [
                'column' => 'created_at',
                'type' => 'Date',
                'value' => '? OR 1=1',
                'boolean' => 'or',
            ],
        ], $sql->wheres);

        $this->assertSame(1, $sql->count());
    }

    public function testWhereDay()
    {
        $this->assertSame(1, DB::table('posts')->whereDay('created_at', '02')->count());
        $this->assertSame(1, DB::table('posts')->whereDay('created_at', 2)->count());
        $this->assertSame(1, DB::table('posts')->whereDay('created_at', new CarbonImmutable('2018-01-02'))->count());
    }

    public function testWhereDayWithInvalidOperator()
    {
        $sql = DB::table('posts')->whereDay('created_at', '? OR 1=1', '02');

        PHPUnit::assertArraySubset([
            [
                'column' => 'created_at',
                'type' => 'Day',
                'value' => '00',
                'boolean' => 'and',
            ],
        ], $sql->wheres);

        $this->assertSame(0, $sql->count());
    }

    public function testOrWhereDay()
    {
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereDay('created_at', '02')->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereDay('created_at', 2)->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereDay('created_at', new CarbonImmutable('2018-01-02'))->count());
    }

    public function testOrWhereDayWithInvalidOperator()
    {
        $sql = DB::table('posts')->where('id', 1)->orWhereDay('created_at', '? OR 1=1', '02');

        PHPUnit::assertArraySubset([
            [
                'column' => 'id',
                'type' => 'Basic',
                'value' => 1,
                'boolean' => 'and',
            ],
            [
                'column' => 'created_at',
                'type' => 'Day',
                'value' => '00',
                'boolean' => 'or',
            ],
        ], $sql->wheres);

        $this->assertSame(1, $sql->count());
    }

    public function testWhereMonth()
    {
        $this->assertSame(1, DB::table('posts')->whereMonth('created_at', '01')->count());
        $this->assertSame(1, DB::table('posts')->whereMonth('created_at', 1)->count());
        $this->assertSame(1, DB::table('posts')->whereMonth('created_at', new CarbonImmutable('2018-01-02'))->count());
    }

    public function testWhereMonthWithInvalidOperator()
    {
        $sql = DB::table('posts')->whereMonth('created_at', '? OR 1=1', '01');

        PHPUnit::assertArraySubset([
            [
                'column' => 'created_at',
                'type' => 'Month',
                'value' => '00',
                'boolean' => 'and',
            ],
        ], $sql->wheres);

        $this->assertSame(0, $sql->count());
    }

    public function testOrWhereMonth()
    {
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereMonth('created_at', '01')->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereMonth('created_at', 1)->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereMonth('created_at', new CarbonImmutable('2018-01-02'))->count());
    }

    public function testOrWhereMonthWithInvalidOperator()
    {
        $sql = DB::table('posts')->where('id', 1)->orWhereMonth('created_at', '? OR 1=1', '01');

        PHPUnit::assertArraySubset([
            [
                'column' => 'id',
                'type' => 'Basic',
                'value' => 1,
                'boolean' => 'and',
            ],
            [
                'column' => 'created_at',
                'type' => 'Month',
                'value' => '00',
                'boolean' => 'or',
            ],
        ], $sql->wheres);

        $this->assertSame(1, $sql->count());
    }

    public function testWhereYear()
    {
        $this->assertSame(1, DB::table('posts')->whereYear('created_at', '2018')->count());
        $this->assertSame(1, DB::table('posts')->whereYear('created_at', 2018)->count());
        $this->assertSame(1, DB::table('posts')->whereYear('created_at', new CarbonImmutable('2018-01-02'))->count());
    }

    #[DefineEnvironment('defineEnvironmentWouldThrowsPDOException')]
    public function testWhereYearWithInvalidOperator()
    {
        $sql = DB::table('posts')->whereYear('created_at', '? OR 1=1', '2018');

        PHPUnit::assertArraySubset([
            [
                'column' => 'created_at',
                'type' => 'Year',
                'value' => '? OR 1=1',
                'boolean' => 'and',
            ],
        ], $sql->wheres);

        $this->assertSame(0, $sql->count());
    }

    public function testOrWhereYear()
    {
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereYear('created_at', '2018')->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereYear('created_at', 2018)->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereYear('created_at', new CarbonImmutable('2018-01-02'))->count());
    }

    #[DefineEnvironment('defineEnvironmentWouldThrowsPDOException')]
    public function testOrWhereYearWithInvalidOperator()
    {
        $sql = DB::table('posts')->where('id', 1)->orWhereYear('created_at', '? OR 1=1', '2018');

        PHPUnit::assertArraySubset([
            [
                'column' => 'id',
                'type' => 'Basic',
                'value' => 1,
                'boolean' => 'and',
            ],
            [
                'column' => 'created_at',
                'type' => 'Year',
                'value' => '? OR 1=1',
                'boolean' => 'or',
            ],
        ], $sql->wheres);

        $this->assertSame(1, $sql->count());
    }

    public function testWhereTime()
    {
        $this->assertSame(1, DB::table('posts')->whereTime('created_at', '03:04:05')->count());
        $this->assertSame(1, DB::table('posts')->whereTime('created_at', new CarbonImmutable('2018-01-02 03:04:05'))->count());
    }

    #[DefineEnvironment('defineEnvironmentWouldThrowsPDOException')]
    public function testWhereTimeWithInvalidOperator()
    {
        $sql = DB::table('posts')->whereTime('created_at', '? OR 1=1', '03:04:05');

        PHPUnit::assertArraySubset([
            [
                'column' => 'created_at',
                'type' => 'Time',
                'value' => '? OR 1=1',
                'boolean' => 'and',
            ],
        ], $sql->wheres);

        $this->assertSame(0, $sql->count());
    }

    public function testOrWhereTime()
    {
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereTime('created_at', '03:04:05')->count());
        $this->assertSame(2, DB::table('posts')->where('id', 1)->orWhereTime('created_at', new CarbonImmutable('2018-01-02 03:04:05'))->count());
    }

    #[DefineEnvironment('defineEnvironmentWouldThrowsPDOException')]
    public function testOrWhereTimeWithInvalidOperator()
    {
        $sql = DB::table('posts')->where('id', 1)->orWhereTime('created_at', '? OR 1=1', '03:04:05');

        PHPUnit::assertArraySubset([
            [
                'column' => 'id',
                'type' => 'Basic',
                'value' => 1,
                'boolean' => 'and',
            ],
            [
                'column' => 'created_at',
                'type' => 'Time',
                'value' => '? OR 1=1',
                'boolean' => 'or',
            ],
        ], $sql->wheres);

        $this->assertSame(1, $sql->count());
    }

    public function testWhereNested()
    {
        $results = DB::table('posts')->where('content', 'Lorem Ipsum.')->whereNested(function ($query) {
            $query->where('title', 'Foo Post')
                ->orWhere('title', 'Bar Post');
        })->count();
        $this->assertSame(2, $results);
    }

    public function testPaginateWithSpecificColumns()
    {
        $result = DB::table('posts')->paginate(5, ['title', 'content']);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertEquals($result->items(), [
            (object) ['title' => 'Foo Post', 'content' => 'Lorem Ipsum.'],
            (object) ['title' => 'Bar Post', 'content' => 'Lorem Ipsum.'],
        ]);
    }

    public function testChunkMap()
    {
        DB::enableQueryLog();

        $results = DB::table('posts')->orderBy('id')->chunkMap(function ($post) {
            return $post->title;
        }, 1);

        $this->assertCount(2, $results);
        $this->assertSame('Foo Post', $results[0]);
        $this->assertSame('Bar Post', $results[1]);
        $this->assertCount(3, DB::getQueryLog());
    }

    public function testPluck()
    {
        // Test SELECT override, since pluck will take the first column.
        $this->assertSame([
            'Foo Post',
            'Bar Post',
        ], DB::table('posts')->select(['content', 'id', 'title'])->pluck('title')->toArray());

        // Test without SELECT override.
        $this->assertSame([
            'Foo Post',
            'Bar Post',
        ], DB::table('posts')->pluck('title')->toArray());

        // Test specific key.
        $this->assertSame([
            1 => 'Foo Post',
            2 => 'Bar Post',
        ], DB::table('posts')->pluck('title', 'id')->toArray());

        $results = DB::table('posts')->pluck('title', 'created_at');

        // Test timestamps (truncates RDBMS differences).
        $this->assertSame([
            '2017-11-12 13:14:15',
            '2018-01-02 03:04:05',
        ], $results->keys()->map(fn ($v) => substr($v, 0, 19))->toArray());
        $this->assertSame([
            'Foo Post',
            'Bar Post',
        ], $results->values()->toArray());

        // Test duplicate keys (a match will override a previous match).
        $this->assertSame([
            'Lorem Ipsum.' => 'Bar Post',
        ], DB::table('posts')->pluck('title', 'content')->toArray());

        // Test custom select query before calling pluck.
        $result = DB::table('posts')
            ->selectSub(DB::table('posts')->selectRaw('COUNT(*)'), 'total_posts_count')
            ->pluck('total_posts_count')
            ->toArray();
        // Cast for database compatibility.
        $this->assertSame(2, (int) $result[0]);
        $this->assertSame(2, (int) $result[1]);
    }

    public function testFetchUsing()
    {
        // Fetch column as a list.
        $this->assertSame([
            'Foo Post',
            'Bar Post',
        ], DB::table('posts')->select(['title'])->fetchUsing(PDO::FETCH_COLUMN)->get()->toArray());

        // Fetch the second column as a list (zero-indexed).
        $this->assertSame([
            'Lorem Ipsum.',
            'Lorem Ipsum.',
        ], DB::table('posts')->select(['title', 'content'])->fetchUsing(PDO::FETCH_COLUMN, 1)->get()->toArray());

        // Fetch two columns as key value pairs.
        $this->assertSame([
            1 => 'Foo Post',
            2 => 'Bar Post',
        ], DB::table('posts')->select(['id', 'title'])->fetchUsing(PDO::FETCH_KEY_PAIR)->get()->toArray());

        // Fetch data as associative array with custom key.
        $result = DB::table('posts')->select(['id', 'title'])->fetchUsing(PDO::FETCH_UNIQUE)->get()->toArray();
        // Note: results are keyed by their post id here.
        $this->assertSame('Foo Post', $result[1]->title);
        $this->assertSame('Bar Post', $result[2]->title);

        // Use a cursor.
        $this->assertSame([
            'Foo Post',
            'Bar Post',
        ], DB::table('posts')->select(['title'])->fetchUsing(PDO::FETCH_COLUMN)->cursor()->collect()->toArray());

        $cursorPaginator = DB::table('posts')
            ->orderBy('id')
            ->fetchUsing(PDO::FETCH_ASSOC)
            ->cursorPaginate(1, ['id', 'title']);
        $this->assertSame([['id' => 1, 'title' => 'Foo Post']], $cursorPaginator->items());
        $this->assertSame(1, (int) $cursorPaginator->nextCursor()?->parameter('id'));

        // Test the default 'object' fetch mode.
        $result = DB::table('posts')->select(['title'])->fetchUsing(PDO::FETCH_OBJ)->get()->toArray();
        $result2 = DB::table('posts')->select(['title'])->fetchUsing()->get()->toArray();
        $this->assertSame('Foo Post', $result[0]->title);
        $this->assertSame('Bar Post', $result[1]->title);
        $this->assertSame('Foo Post', $result2[0]->title);
        $this->assertSame('Bar Post', $result2[1]->title);
    }

    public function testFetchUsingPreservesFalseyRowsAcrossGetAndCursor(): void
    {
        Schema::create('fetch_values', function (Blueprint $table) {
            $table->increments('id');
            $table->string('value')->nullable();
        });

        DB::table('fetch_values')->insert([
            ['value' => null],
            ['value' => ''],
            ['value' => '0'],
            ['value' => 'later'],
        ]);

        $query = DB::table('fetch_values')
            ->select(['id', 'value'])
            ->orderBy('id')
            ->fetchUsing(PDO::FETCH_COLUMN, 1);

        $this->assertSame([null, '', '0', 'later'], $query->get()->all());
        $this->assertSame([null, '', '0', 'later'], $query->cursor()->all());
        $this->assertSame('later', DB::table('fetch_values')->select('value')->fetchUsing(PDO::FETCH_COLUMN)->find(4));

        $fallbackCalled = false;
        $nullQuery = DB::table('fetch_values')->select('value')->fetchUsing(PDO::FETCH_COLUMN);

        $this->assertNull($nullQuery->clone()->where('id', 1)->firstOrFail());
        $this->assertNull($nullQuery->clone()->findOr(1, function () use (&$fallbackCalled) {
            $fallbackCalled = true;

            return 'fallback';
        }));
        $this->assertFalse($fallbackCalled);
    }

    public function testShapeOwningTerminalsIgnoreCustomFetchModes(): void
    {
        $this->assertTrue(DB::table('posts')->fetchUsing(PDO::FETCH_COLUMN)->exists());
        $this->assertSame(2, DB::table('posts')->fetchUsing(PDO::FETCH_COLUMN)->count());
        $this->assertSame(['Foo Post', 'Bar Post'], DB::table('posts')->fetchUsing(PDO::FETCH_COLUMN)->pluck('title')->all());
        $this->assertSame('Foo Post,Bar Post', DB::table('posts')->fetchUsing(PDO::FETCH_COLUMN)->implode('title', ','));
        $this->assertSame('Foo Post', DB::table('posts')->orderBy('id')->fetchUsing(PDO::FETCH_COLUMN)->value('title'));
        $this->assertSame(2, (int) DB::table('posts')->fetchUsing(PDO::FETCH_COLUMN)->rawValue('count(*)'));
        $this->assertSame('Foo Post', DB::table('posts')->where('id', 1)->fetchUsing(PDO::FETCH_COLUMN)->soleValue('title'));

        $paginator = DB::table('posts')->orderBy('id')->fetchUsing(PDO::FETCH_COLUMN)->paginate(1, ['title']);
        $this->assertSame(2, $paginator->total());
        $this->assertSame(['Foo Post'], $paginator->items());

        $groupedPaginator = DB::table('posts')
            ->select('content')
            ->groupBy('content')
            ->orderBy('content')
            ->fetchUsing(PDO::FETCH_COLUMN)
            ->paginate(1, ['content']);
        $this->assertSame(1, $groupedPaginator->total());

        $query = DB::table('posts')->select('title')->orderBy('id')->fetchUsing(PDO::FETCH_COLUMN);
        $this->assertTrue($query->exists());
        $this->assertSame(['Foo Post', 'Bar Post'], $query->get()->all());
    }

    public function testShapeOwningTerminalPreservesBeforeQueryCallbackOwnership(): void
    {
        $callbackCalls = 0;
        $query = DB::table('posts')->orderBy('id')->beforeQuery(function ($query) use (&$callbackCalls) {
            ++$callbackCalls;
            $query->fetchUsing(PDO::FETCH_COLUMN);
        });

        $this->assertSame(['Foo Post', 'Bar Post'], $query->pluck('title')->all());
        $this->assertSame(1, $callbackCalls);
        $this->assertSame(['Foo Post', 'Bar Post'], $query->select('title')->get()->all());
        $this->assertSame(1, $callbackCalls);
    }

    public function testFetchUsingSupportsGroupLimitsAndIdIteration(): void
    {
        $groupLimited = DB::table('posts')
            ->select(['id', 'title', 'content'])
            ->orderBy('id')
            ->groupLimit(1, 'content')
            ->fetchUsing(PDO::FETCH_ASSOC)
            ->first();

        $this->assertIsArray($groupLimited);
        $this->assertSame(
            [],
            array_values(array_filter(
                array_keys($groupLimited),
                fn (string $key) => str_contains($key, 'hypervel_')
            ))
        );

        $this->assertSame(
            [1, 2],
            DB::table('posts')
                ->select(['id', 'title'])
                ->fetchUsing(PDO::FETCH_ASSOC)
                ->lazyById(1)
                ->pluck('id')
                ->all()
        );

        $eachPositions = [];
        DB::table('posts')
            ->selectRaw('title as fetch_key, id, content')
            ->orderBy('id')
            ->fetchUsing(PDO::FETCH_UNIQUE)
            ->each(function (mixed $post, int $position) use (&$eachPositions): void {
                $eachPositions[] = $position;
            }, 2);
        $this->assertSame([0, 1], $eachPositions);

        $eachByIdPositions = [];
        DB::table('posts')
            ->selectRaw('title as fetch_key, id, content')
            ->fetchUsing(PDO::FETCH_UNIQUE)
            ->eachById(function (mixed $post, int $position) use (&$eachByIdPositions): void {
                $eachByIdPositions[] = $position;
            }, 1);
        $this->assertSame([0, 1], $eachByIdPositions);
    }

    public function testFailedSelectRestoresOriginalColumns(): void
    {
        $query = DB::table('posts')->beforeQuery(function (): never {
            throw new RuntimeException('Query failed.');
        });

        try {
            $query->pluck('title');
            $this->fail('Expected the query callback to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Query failed.', $exception->getMessage());
        }

        $this->assertNull($query->columns);
    }

    protected function defineEnvironmentWouldThrowsPDOException($app): void
    {
        $this->afterApplicationCreated(function () {
            if (in_array($this->driver, ['pgsql', 'sqlsrv'])) {
                $this->expectException(PDOException::class);
            }
        });
    }
}
