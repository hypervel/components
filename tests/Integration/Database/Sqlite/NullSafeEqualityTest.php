<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\TestCase;

class NullSafeEqualityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Configure an isolated in-memory SQLite connection.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('database.default', 'null_safe');
        $config->set('database.connections.null_safe', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function testJsonBooleansUseNullSafeEqualityInsteadOfTruthiness(): void
    {
        Schema::create('null_safe_values', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->jsonb('options');
        });

        DB::table('null_safe_values')->insert([
            ['id' => 1, 'options' => '{"enabled":true}'],
            ['id' => 2, 'options' => '{"enabled":false}'],
            ['id' => 3, 'options' => '{"enabled":1}'],
            ['id' => 4, 'options' => '{"enabled":0}'],
            ['id' => 5, 'options' => '{"enabled":2}'],
            ['id' => 6, 'options' => '{"enabled":"hello"}'],
            ['id' => 7, 'options' => '{"enabled":null}'],
            ['id' => 8, 'options' => '{}'],
        ]);

        foreach ([true, false] as $value) {
            // SQLite exposes JSON booleans as the integers 1 and 0.
            $expected = $value ? [1, 3] : [2, 4];

            foreach (['options->enabled', new Expression("options->>'enabled'")] as $column) {
                $this->assertSame($expected, DB::table('null_safe_values')
                    ->whereNullSafeEquals($column, $value)->orderBy('id')->pluck('id')->all());

                $this->assertSame($expected, DB::table('null_safe_values')
                    ->where($column, '<=>', $value)->orderBy('id')->pluck('id')->all());
            }
        }
    }
}
