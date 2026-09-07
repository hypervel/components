<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Support\Facades\DB;

class RefreshCommandTest extends DatabaseTestCase
{
    public function testRefreshWithoutRealpath()
    {
        $this->app->setBasePath(__DIR__);

        $options = [
            '--path' => 'Fixtures/',
        ];

        $this->migrateRefreshWith($options);
    }

    public function testRefreshWithRealpath()
    {
        $options = [
            '--path' => realpath(__DIR__ . '/Fixtures/'),
            '--realpath' => true,
        ];

        $this->migrateRefreshWith($options);
    }

    private function migrateRefreshWith(array $options): void
    {
        if ($this->app->make('config')->get('database.default') !== 'testing') {
            $this->artisan('db:wipe', ['--drop-views' => true]);
        }

        $this->beforeApplicationDestroyed(function () use ($options) {
            $this->artisan('migrate:rollback', $options);
        });

        $this->artisan('migrate:refresh', $options);
        DB::table('members')->insert(['name' => 'foo', 'email' => 'foo@bar', 'password' => 'secret']);
        $this->assertSame(1, DB::table('members')->count());

        $this->artisan('migrate:refresh', $options);
        $this->assertSame(0, DB::table('members')->count());
    }
}
