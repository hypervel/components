<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseSqliteConnectionTest extends SqliteTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('database.default', 'conn1');

        $config->set('database.connections.conn1', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function afterRefreshingDatabase(): void
    {
        if (! Schema::hasTable('json_table')) {
            Schema::create('json_table', function (Blueprint $table) {
                $table->json('json_col')->nullable();
            });
        }
    }

    protected function destroyDatabaseMigrations(): void
    {
        Schema::drop('json_table');
    }

    #[DataProvider('jsonUpdates')]
    public function testJsonUpdatesPreserveValues(string $document, array $values, array $expected): void
    {
        DB::table('json_table')->insert(['json_col' => $document]);

        DB::table('json_table')->update($values);

        $this->assertSame($expected, json_decode(DB::table('json_table')->value('json_col'), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Provide JSON assignments and their stored documents.
     */
    public static function jsonUpdates(): array
    {
        return [
            'root array' => ['[{"keep":1},{"2fa":true,"keep":2}]', ['json_col->[1]->2fa' => false], [['keep' => 1], ['2fa' => false, 'keep' => 2]]],
            'nested array' => ['{"tags":[["small","old"]]}', ['json_col->tags[0][1]' => 'large'], ['tags' => [['small', 'large']]]],
            'replace object' => ['{"settings":{"keep":1,"replace":2}}', ['json_col->settings' => ['replace' => 3]], ['settings' => ['replace' => 3]]],
            'retain null' => ['{"key":1}', ['json_col->key' => null], ['key' => null]],
            'dotted key' => ['{"a.b":1}', ['json_table.json_col->a.b' => 2], ['a.b' => 2]],
            'multiple values' => ['{}', ['json_col->name' => 'John', 'json_table.json_col->active' => true, 'json_col->size' => 1.5, 'json_table.json_col->tags' => ['a', 'b']], ['name' => 'John', 'active' => true, 'size' => 1.5, 'tags' => ['a', 'b']]],
        ];
    }

    public function testJsonUpdateExpressions(): void
    {
        DB::table('json_table')->insert(['json_col' => '{"visits":1}']);

        DB::table('json_table')->increment('json_col->visits');
        DB::table('json_table')->update([
            'json_col->size' => DB::raw('45'),
            'json_col->rating' => DB::query()->selectRaw('cast(? as integer)', [4]),
        ]);

        $this->assertSame(
            ['visits' => 2, 'size' => 45, 'rating' => 4],
            json_decode(DB::table('json_table')->value('json_col'), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testJsonUpdateExceedsOneFunctionArgumentLimit(): void
    {
        DB::table('json_table')->insert(['json_col' => null]);
        $values = $expected = [];

        for ($index = 0; $index < 64; ++$index) {
            $values['json_col->key' . $index] = $index;
            $expected['key' . $index] = $index;
        }

        DB::table('json_table')->update($values);

        $this->assertSame($expected, json_decode(DB::table('json_table')->value('json_col'), true, flags: JSON_THROW_ON_ERROR));
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
            'integer key with arrow ' => [0, 'json_col->foo->bar->0'],
            'integer key with braces' => [1, 'json_col->foo->bar[0]'],
            'integer key missing' => [0, 'json_col->foo->bar[1]'],
            'mixed keys' => [1, 'json_col->foo[1]->baz'],
            'null value' => [1, 'json_col->bar'],
        ];
    }
}
