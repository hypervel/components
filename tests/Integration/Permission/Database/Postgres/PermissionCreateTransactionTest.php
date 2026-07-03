<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Permission\Database\Postgres;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Exceptions\PermissionAlreadyExists;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Permission\TestCase as PermissionTestCase;

#[RequiresDatabase('pgsql')]
class PermissionCreateTransactionTest extends PermissionTestCase
{
    protected array $connectionsToTransact = [];

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('database.default', getenv('DB_CONNECTION') ?: 'testing');
    }

    public function testCreateRaceExceptionDoesNotPoisonPostgresTransaction(): void
    {
        $permissionClass = $this->app->make(PermissionContract::class);

        $permissionClass::creating(static function ($permission) use ($permissionClass): void {
            if ($permission->getAttribute('name') !== 'postgres-raced-permission') {
                return;
            }

            $permissionClass::query()->insert([
                'name' => 'postgres-raced-permission',
                'guard_name' => 'web',
            ]);
        });

        DB::transaction(function () use ($permissionClass): void {
            try {
                $permissionClass::create(['name' => 'postgres-raced-permission']);
                $this->fail('Expected duplicate permission exception was not thrown.');
            } catch (PermissionAlreadyExists) {
                $this->assertTrue(true);
            }

            $permission = $permissionClass::create(['name' => 'postgres-transaction-still-usable']);

            $this->assertSame('postgres-transaction-still-usable', $permission->name);
        });

        $this->assertDatabaseHas(Config::permissionsTable(), [
            'name' => 'postgres-transaction-still-usable',
            'guard_name' => 'web',
        ]);
    }
}
