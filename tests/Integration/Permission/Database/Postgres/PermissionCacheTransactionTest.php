<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Permission\Database\Postgres;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\QueryException;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\Traits\HasRoles;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Permission\Fixtures\Models\UserWithoutHasRoles;
use Hypervel\Tests\Permission\TestCase as PermissionTestCase;
use UnitEnum;

use function Hypervel\Coroutine\parallel;

#[RequiresDatabase('pgsql')]
class PermissionCacheTransactionTest extends PermissionTestCase
{
    public const string SUBJECT_CONNECTION = 'permission_subject_postgres';

    public const string STORAGE_CONNECTION = 'permission_storage_postgres';

    protected array $connectionsToTransact = [];

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        /** @var Repository $config */
        $config = $app->make('config');
        $postgres = $config->array('database.connections.pgsql');

        $config->set([
            'database.default' => getenv('DB_CONNECTION') ?: 'testing',
            'database.connections.' . self::SUBJECT_CONNECTION => $postgres,
            'database.connections.' . self::STORAGE_CONNECTION => $postgres,
            'permission.models.permission' => PostgresPermission::class,
            'permission.models.role' => PostgresRole::class,
            'permission.models.default_model' => PostgresUser::class,
            'auth.providers.users.model' => PostgresUser::class,
            'cache.stores.permission_shared' => ['driver' => 'worker-array'],
            'permission.cache.store' => 'permission_shared',
        ]);
    }

    protected function tearDownInCoroutine(): void
    {
        /** @var DatabaseManager $databaseManager */
        $databaseManager = $this->app->make('db');
        $databaseManager->purge(self::SUBJECT_CONNECTION);
        $databaseManager->purge(self::STORAGE_CONNECTION);
    }

    public function testPostgresTransactionsKeepSharedAssignmentsCommittedAndRollbackSafe(): void
    {
        /** @var DatabaseManager $databaseManager */
        $databaseManager = $this->app->make('db');
        $subjectConnection = $databaseManager->connection(self::SUBJECT_CONNECTION);
        $permissionConnection = $databaseManager->connection(self::STORAGE_CONNECTION);
        $transactionManager = new DatabaseTransactionsManager;
        $subjectConnection->setTransactionManager($transactionManager);
        $permissionConnection->setTransactionManager($transactionManager);

        $user = PostgresUser::create(['email' => 'postgres-transaction@example.com']);
        $role = PostgresRole::create(['name' => 'postgres-editor']);

        $this->assertFalse($user->hasRole($role));

        $permissionConnection->beginTransaction();

        try {
            $user->assignRole($role);
            $this->assertTrue($user->hasRole($role));
        } finally {
            $permissionConnection->rollBack();
        }

        [$hasRoleAfterRollback] = parallel([
            fn (): bool => PostgresUser::findOrFail($user->getKey())->hasRole($role),
        ]);

        $this->assertFalse($hasRoleAfterRollback);

        $permissionConnection->transaction(fn () => $user->assignRole($role));

        [$hasRoleAfterCommit] = parallel([
            fn (): bool => PostgresUser::findOrFail($user->getKey())->hasRole($role),
        ]);

        $this->assertTrue($hasRoleAfterCommit);
    }

    public function testCaughtCleanupFailureRollsBackTheDeleteSavepoint(): void
    {
        /** @var DatabaseManager $databaseManager */
        $databaseManager = $this->app->make('db');
        $permissionConnection = $databaseManager->connection(self::STORAGE_CONNECTION);
        $permissionConnection->setTransactionManager(new DatabaseTransactionsManager);

        $user = PostgresUser::create(['email' => 'postgres-delete@example.com']);
        $role = PostgresRole::create(['name' => 'postgres-editor']);
        $permission = PostgresPermission::create(['name' => 'postgres-publish']);
        $user->assignRole($role);
        $role->givePermissionTo($permission);

        $permissionConnection->unprepared(<<<'SQL'
CREATE FUNCTION fail_permission_role_cleanup() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'forced PostgreSQL role permission cleanup failure';
END;
$$ LANGUAGE plpgsql
SQL);
        $permissionConnection->unprepared(<<<'SQL'
CREATE TRIGGER fail_permission_role_cleanup
BEFORE DELETE ON role_has_permissions
FOR EACH ROW EXECUTE FUNCTION fail_permission_role_cleanup()
SQL);

        $caught = null;

        try {
            $permissionConnection->transaction(function () use ($permissionConnection, $role, &$caught): void {
                try {
                    $role->delete();
                } catch (QueryException $exception) {
                    $caught = $exception;
                }

                $permissionConnection->table('roles')
                    ->where('id', $role->getKey())
                    ->update(['name' => 'postgres-editor-after-recovery']);
            });
        } finally {
            $permissionConnection->unprepared('DROP TRIGGER fail_permission_role_cleanup ON role_has_permissions');
            $permissionConnection->unprepared('DROP FUNCTION fail_permission_role_cleanup()');
        }

        $this->assertInstanceOf(QueryException::class, $caught);
        $this->assertSame(1, $permissionConnection->table('roles')->where('id', $role->getKey())->count());
        $this->assertSame(1, $permissionConnection->table('model_has_roles')->count());
        $this->assertSame(1, $permissionConnection->table('role_has_permissions')->count());
        $this->assertSame(
            'postgres-editor-after-recovery',
            $permissionConnection->table('roles')->where('id', $role->getKey())->value('name'),
        );
    }
}

class PostgresUser extends UserWithoutHasRoles
{
    use HasRoles;

    protected UnitEnum|string|null $connection = PermissionCacheTransactionTest::SUBJECT_CONNECTION;
}

class PostgresRole extends Role
{
    protected UnitEnum|string|null $connection = PermissionCacheTransactionTest::STORAGE_CONNECTION;
}

class PostgresPermission extends Permission
{
    protected UnitEnum|string|null $connection = PermissionCacheTransactionTest::STORAGE_CONNECTION;
}
