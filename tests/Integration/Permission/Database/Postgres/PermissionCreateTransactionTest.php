<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Permission\Database\Postgres;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Exceptions\PermissionAlreadyExists;
use Hypervel\Permission\Exceptions\RoleAlreadyExists;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Permission\TestCase as PermissionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

#[RequiresDatabase('pgsql')]
class PermissionCreateTransactionTest extends PermissionTestCase
{
    protected array $connectionsToTransact = [];

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('database.default', getenv('DB_CONNECTION') ?: 'testing');
    }

    /**
     * @param class-string $contract
     * @param class-string<PermissionAlreadyExists|RoleAlreadyExists> $exception
     */
    #[DataProvider('createdModels')]
    public function testCreateRaceExceptionDoesNotPoisonPostgresTransaction(string $contract, string $exception): void
    {
        $model = $this->app->make($contract);

        $model::creating(static function (Model $created) use ($model): void {
            if ($created->getAttribute('name') !== 'postgres-raced') {
                return;
            }

            $model::query()->insert([
                'name' => 'postgres-raced',
                'guard_name' => 'web',
            ]);
        });

        $caught = null;

        DB::transaction(function () use ($model, &$caught): void {
            try {
                $model::create(['name' => 'postgres-raced']);
            } catch (PermissionAlreadyExists|RoleAlreadyExists $alreadyExists) {
                $caught = $alreadyExists;
            }

            $model::create(['name' => 'postgres-transaction-still-usable']);
        });

        $this->assertInstanceOf($exception, $caught);
        $this->assertDatabaseHas($model->getTable(), [
            'name' => 'postgres-transaction-still-usable',
            'guard_name' => 'web',
        ]);
    }

    /**
     * Get the models whose create race is checked.
     */
    public static function createdModels(): array
    {
        return [
            'permission' => [PermissionContract::class, PermissionAlreadyExists::class],
            'role' => [RoleContract::class, RoleAlreadyExists::class],
        ];
    }
}
