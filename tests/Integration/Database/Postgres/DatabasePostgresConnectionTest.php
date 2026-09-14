<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Postgres;

use Hypervel\Database\Query\JoinClause;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresOperatingSystem('Linux|Darwin')]
#[RequiresPhpExtension('pdo_pgsql')]
class DatabasePostgresConnectionTest extends PostgresTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        if (! Schema::hasTable('json_table')) {
            Schema::create('json_table', function (Blueprint $table) {
                $table->json('json_col')->nullable();
                $table->string('label')->nullable();
            });
        }
    }

    protected function destroyDatabaseMigrations(): void
    {
        Schema::drop('json_table');
    }

    #[DataProvider('jsonWhereNullDataProvider')]
    public function testJsonWhereNull($expected, $key, array $value = ['value' => 123])
    {
        DB::table('json_table')->insert(['json_col' => json_encode($value)]);

        $this->assertSame($expected, DB::table('json_table')->whereNull("json_col->{$key}")->exists());
    }

    #[DataProvider('jsonWhereNullDataProvider')]
    public function testJsonWhereNotNull($expected, $key, array $value = ['value' => 123])
    {
        DB::table('json_table')->insert(['json_col' => json_encode($value)]);

        $this->assertSame(! $expected, DB::table('json_table')->whereNotNull("json_col->{$key}")->exists());
    }

    public static function jsonWhereNullDataProvider()
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
            'array index not exists' => [true, '[0]', [1 => 'invalid']],
            'array index exists and null' => [true, '[0]', [null]],
            'array index exists and "null"' => [false, '[0]', ['null']],
            'array index exists and not null' => [false, '[0]', [false]],
            'multiple array index not exists' => [true, '[0][0]', [1 => [1 => 'invalid']]],
            'multiple array index exists and null' => [true, '[0][0]', [[null]]],
            'multiple array index exists and "null"' => [false, '[0][0]', [['null']]],
            'multiple array index exists and not null' => [false, '[0][0]', [[false]]],
            'nested array index not exists' => [true, 'nested[0]', ['nested' => [1 => 'nested->invalid']]],
            'nested array index exists and null' => [true, 'nested->value[1]', ['nested' => ['value' => [0, null]]]],
            'nested array index exists and "null"' => [false, 'nested->value[1]', ['nested' => ['value' => [0, 'null']]]],
            'nested array index exists and not null' => [false, 'nested->value[1]', ['nested' => ['value' => [0, false]]]],
        ];
    }

    public function testJsonPathUpdate()
    {
        DB::table('json_table')->insert([
            ['json_col' => '{"foo":["bar"]}'],
            ['json_col' => '{"foo":["baz"]}'],
            ['json_col' => '{"foo":[["array"]]}'],
        ]);

        $updatedCount = DB::table('json_table')->where('json_col->foo[0]', 'baz')->update([
            'json_col->foo[0]' => 'updated',
        ]);
        $this->assertSame(1, $updatedCount);

        $updatedCount = DB::table('json_table')->where('json_col->foo[0][0]', 'array')->update([
            'json_col->foo[0][0]' => 'updated',
        ]);
        $this->assertSame(1, $updatedCount);
    }

    public function testJsonPathEscaping(): void
    {
        foreach (['App\Models\User', 'a"b', "O'Brien\\\"x"] as $key) {
            $path = 'json_col->' . $key . '[0]';
            DB::table('json_table')->insert(['json_col' => json_encode([$key => ['before']], JSON_THROW_ON_ERROR)]);

            $this->assertSame('before', DB::table('json_table')->where($path, 'before')->value($path));
            $this->assertSame(1, DB::table('json_table')->where($path, 'before')->update([$path => 'after']));
            $this->assertSame(
                [$key => ['after']],
                json_decode(DB::table('json_table')->where($path, 'after')->value('json_col'), true, flags: JSON_THROW_ON_ERROR),
            );
        }
    }

    #[DataProvider('jsonUpdateOperations')]
    public function testJsonUpdatesKeepAllPathsAndBindings(string $method, bool $join, bool $limit): void
    {
        DB::table('json_table')->insert(['json_col' => '{"a.b":0,"object":{"old":1},"keep":2}', 'label' => 'before']);

        $query = DB::table('json_table', 'target')->where('label', 'before');

        if ($join) {
            $query->joinSub(DB::query()->selectRaw('?::integer as marker, ?::jsonb as json_col', [7, '{"keep":99}']), 'source', function (JoinClause $join): void {
                $join->where('source.marker', 7);
            });
        }

        if ($limit) {
            $query->limit(1);
        }

        $values = [
            'target.json_col->a.b' => 3,
            'label' => 'after',
            'json_col->object' => ['new' => true],
            'json_col->nullable' => null,
            'target.json_col->raw' => DB::raw("'4'::jsonb"),
        ];

        if ($method === 'update') {
            $values['target.json_col->subquery'] = DB::query()->selectRaw('?::jsonb', ['5']);
        }

        $this->assertSame(1, $query->{$method}($values));

        $expected = ['a.b' => 3, 'object' => ['new' => true], 'keep' => 2, 'nullable' => null, 'raw' => 4];
        if ($method === 'update') {
            $expected['subquery'] = 5;
        }

        $actual = json_decode(DB::table('json_table')->where('label', 'after')->value('json_col'), true, flags: JSON_THROW_ON_ERROR);
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    /**
     * Provide PostgreSQL update compilation paths.
     */
    public static function jsonUpdateOperations(): array
    {
        return [
            'update' => ['update', false, false],
            'joined update' => ['update', true, false],
            'limited update' => ['update', false, true],
            'update from' => ['updateFrom', true, false],
        ];
    }

    #[DataProvider('jsonContainsKeyDataProvider')]
    public function testWhereJsonContainsKey($count, $column)
    {
        DB::table('json_table')->insert([
            ['json_col' => '{"foo":{"bar":["baz"]}}'],
            ['json_col' => '{"foo":{"bar":false}}'],
            ['json_col' => '{"foo":{}}'],
            ['json_col' => '{"foo":[{"bar":"bar"},{"baz":"baz"}]}'],
            ['json_col' => '{"bar":null}'],
        ]);

        $this->assertSame($count, DB::table('json_table')->whereJsonContainsKey($column)->count());
    }

    public static function jsonContainsKeyDataProvider()
    {
        return [
            'string key' => [4, 'json_col->foo'],
            'nested key exists' => [2, 'json_col->foo->bar'],
            'string key missing' => [0, 'json_col->none'],
            'integer key with arrow ' => [1, 'json_col->foo->bar->0'],
            'integer key with braces' => [1, 'json_col->foo->bar[0]'],
            'integer key missing' => [0, 'json_col->foo->bar[1]'],
            'mixed keys' => [1, 'json_col->foo[1]->baz'],
            'null value' => [1, 'json_col->bar'],
        ];
    }
}
