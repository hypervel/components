<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MySql;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Query\JoinClause;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresOperatingSystem('Linux|Darwin')]
#[RequiresPhpExtension('pdo_mysql')]
class DatabaseMySqlConnectionTest extends MySqlTestCase
{
    public const string TABLE = 'player';

    public const string FLOAT_COL = 'float_col';

    public const string JSON_COL = 'json_col';

    public const float FLOAT_VAL = 0.2;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('database.connections.mysql_no_backslash_escapes', array_replace(
            $config->array('database.connections.mysql'),
            ['modes' => ['NO_BACKSLASH_ESCAPES']],
        ));
    }

    protected function afterRefreshingDatabase(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->json(self::JSON_COL)->nullable();
                $table->float(self::FLOAT_COL)->nullable();
            });
        }
    }

    protected function destroyDatabaseMigrations(): void
    {
        Schema::drop(self::TABLE);
    }

    #[DataProvider('floatComparisonsDataProvider')]
    public function testJsonFloatComparison(float $value, string $operator, bool $shouldMatch): void
    {
        DB::table(self::TABLE)->insert([self::JSON_COL => '{"rank":' . self::FLOAT_VAL . '}']);

        $this->assertSame(
            $shouldMatch,
            DB::table(self::TABLE)->where(self::JSON_COL . '->rank', $operator, $value)->exists(),
            self::JSON_COL . '->rank should ' . ($shouldMatch ? '' : 'not ') . "be {$operator} {$value}"
        );
    }

    /**
     * Provide JSON float comparisons.
     *
     * @return list<array{0: float, 1: string, 2: bool}>
     */
    public static function floatComparisonsDataProvider(): array
    {
        return [
            [0.2, '=', true],
            [0.2, '>', false],
            [0.2, '<', false],
            [0.1, '=', false],
            [0.1, '<', false],
            [0.1, '>', true],
            [0.3, '=', false],
            [0.3, '<', true],
            [0.3, '>', false],
        ];
    }

    public function testFloatValueStoredCorrectly(): void
    {
        DB::table(self::TABLE)->insert([self::FLOAT_COL => self::FLOAT_VAL]);

        $this->assertSame(self::FLOAT_VAL, DB::table(self::TABLE)->value(self::FLOAT_COL));
    }

    #[DataProvider('jsonWhereNullDataProvider')]
    public function testJsonWhereNull(bool $expected, string $key, array $value = ['value' => 123]): void
    {
        DB::table(self::TABLE)->insert([self::JSON_COL => json_encode($value)]);

        $this->assertSame($expected, DB::table(self::TABLE)->whereNull(self::JSON_COL . '->' . $key)->exists());
    }

    #[DataProvider('jsonWhereNullDataProvider')]
    public function testJsonWhereNotNull(bool $expected, string $key, array $value = ['value' => 123]): void
    {
        DB::table(self::TABLE)->insert([self::JSON_COL => json_encode($value)]);

        $this->assertSame(! $expected, DB::table(self::TABLE)->whereNotNull(self::JSON_COL . '->' . $key)->exists());
    }

    /**
     * Provide JSON null comparisons.
     *
     * @return array<string, array{0: bool, 1: string, 2?: array<array-key, mixed>}>
     */
    public static function jsonWhereNullDataProvider(): array
    {
        return [
            'key not exists' => [true, 'invalid'],
            'key exists and null' => [true, 'value', ['value' => null]],
            'key exists and "null"' => [false, 'value', ['value' => 'null']],
            'key exists and not null' => [false, 'value', ['value' => false]],
            'nested key not exists' => [true, 'nested->invalid'],
            'nested key exists and null' => [true, 'nested->value', ['nested' => ['value' => null]]],
            'nested key exists and "null"' => [false, 'nested->value', ['nested' => ['value' => 'null']]],
            'nested key exists and not null' => [false, 'nested->value', ['nested' => ['value' => false]]],
            'array index not exists' => [false, '[0]', [1 => 'invalid']],
            'array index exists and null' => [true, '[0]', [null]],
            'array index exists and "null"' => [false, '[0]', ['null']],
            'array index exists and not null' => [false, '[0]', [false]],
            'nested array index not exists' => [false, 'nested[0]', ['nested' => [1 => 'nested->invalid']]],
            'nested array index exists and null' => [true, 'nested->value[1]', ['nested' => ['value' => [0, null]]]],
            'nested array index exists and "null"' => [false, 'nested->value[1]', ['nested' => ['value' => [0, 'null']]]],
            'nested array index exists and not null' => [false, 'nested->value[1]', ['nested' => ['value' => [0, false]]]],
        ];
    }

    public function testJsonPathUpdate(): void
    {
        DB::table(self::TABLE)->insert([
            [self::JSON_COL => '{"foo":["bar"]}'],
            [self::JSON_COL => '{"foo":["baz"]}'],
        ]);
        $updatedCount = DB::table(self::TABLE)->where(self::JSON_COL . '->foo[0]', 'baz')->update([
            self::JSON_COL . '->foo[0]' => 'updated',
        ]);
        $this->assertSame(1, $updatedCount);
    }

    #[DataProvider('jsonPathConnections')]
    public function testJsonPathEscaping(string $connectionName): void
    {
        $connection = DB::connection($connectionName);

        foreach (['App\Models\User', 'a"b', "O'Brien\\x"] as $key) {
            $path = self::JSON_COL . '->' . $key . '[0]';
            $connection->table(self::TABLE)->insert([self::JSON_COL => json_encode([$key => ['before']], JSON_THROW_ON_ERROR)]);

            $this->assertSame('before', $connection->table(self::TABLE)->where($path, 'before')->value($path));
            $this->assertSame(1, $connection->table(self::TABLE)->where($path, 'before')->update([$path => 'after']));
            $this->assertSame(
                [$key => ['after']],
                json_decode($connection->table(self::TABLE)->where($path, 'after')->value(self::JSON_COL), true, flags: JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * Provide connections with each MySQL backslash escaping mode.
     *
     * @return list<array{string}>
     */
    public static function jsonPathConnections(): array
    {
        return [['mysql'], ['mysql_no_backslash_escapes']];
    }

    #[DataProvider('jsonUpdateJoins')]
    public function testJoinedJsonUpdatesKeepAllPathsAndBindings(bool $subquery): void
    {
        DB::table(self::TABLE)->insert([self::JSON_COL => '{}', self::FLOAT_COL => 7]);
        $query = DB::table(self::TABLE);

        if ($subquery) {
            $query->joinSub(DB::query()->selectRaw('? as marker', [7]), 'source', function (JoinClause $join): void {
                $join->where('source.marker', 7);
            });
        } else {
            $query->join(self::TABLE . ' as other', 'player.float_col', '=', 'other.float_col');
        }

        $this->assertSame(1, $query->where('player.float_col', 7)->update([
            'player.json_col->name' => 'John',
            'player.float_col' => 8,
            'player.json_col->tags' => [1, 2],
            'player.json_col->active' => true,
            'player.json_col->nullable' => null,
            'player.json_col->raw' => DB::raw('9'),
            'player.json_col->rating' => DB::query()->selectRaw('cast(? as signed)', [4]),
        ]));

        $actual = json_decode(DB::table(self::TABLE)->where(self::FLOAT_COL, 8)->value(self::JSON_COL), true, flags: JSON_THROW_ON_ERROR);
        ksort($actual);
        $this->assertSame(['active' => true, 'name' => 'John', 'nullable' => null, 'rating' => 4, 'raw' => 9, 'tags' => [1, 2]], $actual);
    }

    /**
     * Provide joins with and without source and condition bindings.
     */
    public static function jsonUpdateJoins(): array
    {
        return ['column join' => [false], 'subquery join' => [true]];
    }

    public function testSingleTableJsonUpdatesPreserveAssignmentOrder(): void
    {
        DB::table(self::TABLE)->insert([self::JSON_COL => '{"a":0,"b":0}', self::FLOAT_COL => 7]);

        DB::table(self::TABLE)->update([
            'json_col->a' => 1,
            'float_col' => DB::raw("json_extract(json_col, '$.a')"),
            'json_col->b' => DB::raw('float_col + 1'),
        ]);

        $this->assertSame(1.0, DB::table(self::TABLE)->value(self::FLOAT_COL));
        $this->assertEquals(['a' => 1, 'b' => 2], json_decode(DB::table(self::TABLE)->value(self::JSON_COL), true, flags: JSON_THROW_ON_ERROR));
    }

    #[DataProvider('jsonContainsKeyDataProvider')]
    public function testWhereJsonContainsKey(int $count, string $column): void
    {
        DB::table(self::TABLE)->insert([
            ['json_col' => '{"foo":{"bar":["baz"]}}'],
            ['json_col' => '{"foo":{"bar":false}}'],
            ['json_col' => '{"foo":{}}'],
            ['json_col' => '{"foo":[{"bar":"bar"},{"baz":"baz"}]}'],
            ['json_col' => '{"bar":null}'],
        ]);

        $this->assertSame($count, DB::table(self::TABLE)->whereJsonContainsKey($column)->count());
    }

    /**
     * Provide JSON paths and their expected match counts.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function jsonContainsKeyDataProvider(): array
    {
        return [
            'string key' => [4, 'json_col->foo'],
            'nested key exists' => [2, 'json_col->foo->bar'],
            'string key missing' => [0, 'json_col->none'],
            'integer key with arrow ' => [0, 'json_col->foo->bar->0'],
            'integer key with braces' => [2, 'json_col->foo->bar[0]'],
            'integer key missing' => [0, 'json_col->foo->bar[1]'],
            'mixed keys' => [1, 'json_col->foo[1]->baz'],
            'null value' => [1, 'json_col->bar'],
        ];
    }

    public function testLastInsertIdIsPreserved(): void
    {
        if (! Schema::hasTable('auto_id_table')) {
            Schema::create('auto_id_table', function (Blueprint $table): void {
                $table->id();
            });
        }

        try {
            $callbackExecuted = false;
            DB::listen(function (QueryExecuted $event) use (&$callbackExecuted): void {
                DB::getPdo()->query('SELECT 1');
                $callbackExecuted = true;
            });

            $id = DB::table('auto_id_table')->insertGetId([]);
            $this->assertTrue($callbackExecuted, 'The query listener was not executed.');
            $this->assertSame(1, $id);
        } finally {
            Schema::drop('auto_id_table');
        }
    }
}
