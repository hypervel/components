<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class RefreshCommandTest extends TestCase
{
    public function testRefreshWithoutRealpath()
    {
        $this->app->setBasePath(__DIR__);

        $options = [
            '--path' => 'stubs/',
        ];

        $this->migrateRefreshWith($options);
    }

    public function testRefreshWithRealpath()
    {
        $options = [
            '--path' => realpath(__DIR__ . '/stubs/'),
            '--realpath' => true,
        ];

        $this->migrateRefreshWith($options);
    }

    private function migrateRefreshWith(array $options)
    {
        if ($this->app['config']->get('database.default') !== 'testing') {
            $this->artisan('db:wipe', ['--drop-views' => true]);
        }

        $this->beforeApplicationDestroyed(function () use ($options) {
            $this->artisan('migrate:rollback', $options);
        });

        $this->artisan('migrate:refresh', $options);
        DB::table('members')->insert(['name' => 'foo', 'email' => 'foo@bar', 'password' => 'secret']);
        $this->assertEquals(1, DB::table('members')->count());

        $this->artisan('migrate:refresh', $options);
        $this->assertEquals(0, DB::table('members')->count());
    }
}
