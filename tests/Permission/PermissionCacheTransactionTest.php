<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Cache\ModelCacheCoordinator;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Permission\Exceptions\PermissionConnectionMismatch;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\PermissionPartition;
use Hypervel\Permission\Support\PermissionRelationContext;
use Hypervel\Permission\Traits\HasRoles;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\Fixtures\Models\UserWithoutHasRoles;
use RuntimeException;
use UnitEnum;

use function Hypervel\Coroutine\parallel;

class PermissionCacheTransactionTest extends TestCase
{
    public const string SUBJECT_CONNECTION = 'permission_subject';

    public const string STORAGE_CONNECTION = 'permission_storage';

    private Filesystem $filesystem;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->temporaryDirectory = ParallelTesting::tempDir('PermissionCacheTransactionTest');
        $this->filesystem->deleteDirectory($this->temporaryDirectory);
        $this->filesystem->ensureDirectoryExists($this->temporaryDirectory);
    }

    protected function tearDownInCoroutine(): void
    {
        $databaseManager = $this->app->make('db');
        $databaseManager->purge(self::SUBJECT_CONNECTION);
        $databaseManager->purge(self::STORAGE_CONNECTION);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testRolledBackAssignmentReadInsideTransactionIsNotPublishedToSharedCache(): void
    {
        $this->useSharedPermissionCache();

        $connection = $this->testUser->getConnection();

        $this->assertFalse($this->testUser->hasRole('testRole'));

        $connection->beginTransaction();

        try {
            $this->testUser->assignRole('testRole');

            $this->assertTrue($this->testUser->hasRole('testRole'));
        } finally {
            $connection->rollBack();
        }

        $this->app->make(PermissionRegistrar::class)->clearPermissionsCollection();

        [$hasRole] = parallel([
            fn (): bool => User::findOrFail($this->testUser->getKey())->hasRole('testRole'),
        ]);

        $this->assertFalse($hasRole);
    }

    public function testCommittedAssignmentInvalidatesTheWarmSharedView(): void
    {
        $this->useSharedPermissionCache();
        $connection = $this->testUser->getConnection();

        $this->assertFalse($this->testUser->hasRole('testRole'));

        $connection->beginTransaction();
        $this->testUser->assignRole('testRole');
        $this->assertTrue($this->testUser->hasRole('testRole'));
        $connection->commit();

        [$hasRole] = parallel([
            fn (): bool => User::findOrFail($this->testUser->getKey())->hasRole('testRole'),
        ]);

        $this->assertTrue($hasRole);
    }

    public function testNestedRollbackClearsOnlyTheNestedTransactionView(): void
    {
        $this->useSharedPermissionCache();
        $connection = $this->testUser->getConnection();

        $connection->beginTransaction();
        $this->testUser->assignRole('testRole');
        $this->assertTrue($this->testUser->hasRole('testRole'));

        $connection->beginTransaction();
        $this->testUser->removeRole('testRole');
        $this->assertFalse($this->testUser->hasRole('testRole'));
        $connection->rollBack();

        $this->assertTrue($this->testUser->hasRole('testRole'));

        $connection->rollBack();

        [$hasRole] = parallel([
            fn (): bool => User::findOrFail($this->testUser->getKey())->hasRole('testRole'),
        ]);

        $this->assertFalse($hasRole);
    }

    public function testRolledBackCatalogReadDoesNotReplaceTheCommittedCatalog(): void
    {
        $this->useSharedPermissionCache();
        $connection = $this->testUserRole->getConnection();

        $this->assertSame($this->testUserRole->getKey(), Role::findByName('testRole')->getKey());

        $connection->beginTransaction();
        $this->testUserRole->name = 'renamed-role';
        $this->testUserRole->save();
        $this->assertSame($this->testUserRole->getKey(), Role::findByName('renamed-role')->getKey());
        $connection->rollBack();

        $this->assertSame($this->testUserRole->getKey(), Role::findByName('testRole')->getKey());
    }

    public function testRolledBackReverseSyncCannotPublishAssignmentsUnderTheCommittedToken(): void
    {
        $this->useSharedPermissionCache();
        $retainedUser = User::create(['email' => 'retained-sync@example.com']);
        $this->testUserRole->assignToModels([$this->testUser, $retainedUser]);
        $connection = $this->testUserRole->getConnection();

        $connection->beginTransaction();

        try {
            $this->testUserRole->syncModels($retainedUser);
            $this->assertFalse($this->testUser->hasRole('testRole'));
        } finally {
            $connection->rollBack();
        }

        [$hasRole] = parallel([
            fn (): bool => User::findOrFail($this->testUser->getKey())->hasRole('testRole'),
        ]);

        $this->assertTrue($hasRole);
    }

    public function testRolledBackRoleDeleteCannotPublishAssignmentsUnderTheCommittedToken(): void
    {
        $this->useSharedPermissionCache();
        $secondRole = Role::create(['name' => 'second-role']);
        $this->testUser->assignRole($this->testUserRole, $secondRole);
        $connection = $secondRole->getConnection();

        $connection->beginTransaction();

        try {
            $secondRole->delete();
            $this->assertTrue($this->testUser->hasRole('testRole'));
        } finally {
            $connection->rollBack();
        }

        [$hasRole] = parallel([
            fn (): bool => User::findOrFail($this->testUser->getKey())->hasRole('second-role'),
        ]);

        $this->assertTrue($hasRole);
    }

    public function testRepeatedReverseSyncPublishesAFreshCommittedToken(): void
    {
        $this->useSharedPermissionCache();
        $secondUser = User::create(['email' => 'second-sync@example.com']);
        $this->testUserRole->assignToModels([$this->testUser, $secondUser]);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $sharedToken = $registrar->modelAssignmentCacheToken();
        $connection = $this->testUserRole->getConnection();

        $connection->beginTransaction();
        $this->testUserRole->syncModels($secondUser);
        $firstProvisionalToken = $registrar->modelAssignmentCacheToken();
        $this->assertFalse($this->testUser->hasRole('testRole'));

        $this->testUserRole->syncModels($this->testUser);
        $secondProvisionalToken = $registrar->modelAssignmentCacheToken();
        $connection->commit();

        $this->assertNotSame($sharedToken, $firstProvisionalToken);
        $this->assertNotSame($firstProvisionalToken, $secondProvisionalToken);
        $committedToken = $registrar->modelAssignmentCacheToken();
        $this->assertNotSame($sharedToken, $committedToken);
        $this->assertNotSame($firstProvisionalToken, $committedToken);
        $this->assertNotSame($secondProvisionalToken, $committedToken);

        [$hasRole] = parallel([
            fn (): bool => User::findOrFail($this->testUser->getKey())->hasRole('testRole'),
        ]);

        $this->assertTrue($hasRole);
    }

    public function testNestedRotationRollbackInstallsAFreshOuterProvisionalToken(): void
    {
        $this->useSharedPermissionCache();
        $secondUser = User::create(['email' => 'nested-sync@example.com']);
        $this->testUserRole->assignToModels([$this->testUser, $secondUser]);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $sharedToken = $registrar->modelAssignmentCacheToken();
        $connection = $this->testUserRole->getConnection();

        $connection->beginTransaction();
        $this->testUserRole->syncModels($secondUser);
        $outerProvisionalToken = $registrar->modelAssignmentCacheToken();
        $this->assertFalse($this->testUser->hasRole('testRole'));
        $outerAssignmentCacheKey = $this->roleAssignmentCacheKey(
            $this->testUser,
            $outerProvisionalToken,
        );
        $this->assertTrue($registrar->getCacheRepository()->has($outerAssignmentCacheKey));

        $connection->beginTransaction();
        $this->testUserRole->syncModels($this->testUser);
        $innerProvisionalToken = $registrar->modelAssignmentCacheToken();
        $this->assertFalse($secondUser->hasRole('testRole'));
        $connection->rollBack();

        $afterRollbackToken = $registrar->modelAssignmentCacheToken();

        $this->assertNotSame($sharedToken, $afterRollbackToken);
        $this->assertNotSame($outerProvisionalToken, $afterRollbackToken);
        $this->assertNotSame($innerProvisionalToken, $afterRollbackToken);
        $afterRollbackCacheKey = $this->roleAssignmentCacheKey(
            $this->testUser,
            $afterRollbackToken,
        );
        $this->assertTrue($registrar->getCacheRepository()->has($outerAssignmentCacheKey));
        $this->assertFalse($registrar->getCacheRepository()->has($afterRollbackCacheKey));
        $this->assertFalse($this->testUser->hasRole('testRole'));
        $this->assertTrue($registrar->getCacheRepository()->has($afterRollbackCacheKey));
        $this->assertTrue($secondUser->hasRole('testRole'));

        $connection->commit();

        $committedToken = $registrar->modelAssignmentCacheToken();
        $this->assertNotSame($sharedToken, $committedToken);
        $this->assertNotSame($outerProvisionalToken, $committedToken);
        $this->assertNotSame($innerProvisionalToken, $committedToken);
        $this->assertNotSame($afterRollbackToken, $committedToken);
    }

    public function testEarlyDeletedListenerCannotPublishARolledBackHardDeleteUnderTheCommittedToken(): void
    {
        $listenerHasRole = null;
        $listenerToken = null;
        $userId = $this->testUser->getKey();

        EarlyDeletedListenerRole::deleted(static function () use (&$listenerHasRole, &$listenerToken, $userId): void {
            $registrar = app(PermissionRegistrar::class);
            $listenerToken = $registrar->modelAssignmentCacheToken();
            $listenerHasRole = User::findOrFail($userId)->hasRole('early-listener-role');
        });

        config()->set('permission.models.role', EarlyDeletedListenerRole::class);
        $this->flushPermissionState();

        $role = EarlyDeletedListenerRole::create(['name' => 'early-listener-role']);
        $this->testUser->assignRole($role);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $committedToken = $registrar->modelAssignmentCacheToken();
        $connection = $role->getConnection();

        $connection->beginTransaction();

        try {
            $role->delete();
        } finally {
            $connection->rollBack();
        }

        $this->assertFalse($listenerHasRole);
        $this->assertIsString($listenerToken);
        $this->assertNotSame($committedToken, $listenerToken);
        $this->assertSame($committedToken, $registrar->modelAssignmentCacheToken());

        [$hasRole] = parallel([
            fn (): bool => User::findOrFail($userId)->hasRole('early-listener-role'),
        ]);

        $this->assertTrue($hasRole);
    }

    public function testAssignmentTokenUsesOnlyTheCurrentConnectionSettlement(): void
    {
        [$subjectConnection] = $this->setUpAliasedPermissionStorage();
        config()->set('permission.models.permission', DynamicAliasedPermission::class);
        $this->flushPermissionState();

        /** @var DatabaseManager $databaseManager */
        $databaseManager = $this->app->make('db');
        $registrar = $this->app->make(PermissionRegistrar::class);
        $committedToken = $databaseManager->usingConnection(
            self::SUBJECT_CONNECTION,
            fn (): string => $registrar->modelAssignmentCacheToken(),
        );

        $provisionalToken = $databaseManager->usingConnection(
            self::SUBJECT_CONNECTION,
            function () use ($registrar, $subjectConnection): string {
                $subjectConnection->beginTransaction();
                $registrar->rotateModelAssignmentCacheTokenAfterMutation(null);

                return $registrar->modelAssignmentCacheToken();
            },
        );
        $storageToken = $databaseManager->usingConnection(
            self::STORAGE_CONNECTION,
            fn (): string => $registrar->modelAssignmentCacheToken(),
        );

        $this->assertNotSame($committedToken, $provisionalToken);
        $this->assertSame($committedToken, $storageToken);

        $databaseManager->usingConnection(
            self::SUBJECT_CONNECTION,
            fn () => $subjectConnection->rollBack(),
        );
    }

    public function testAssignmentTokenSettlementsRemainIndependentAcrossConnectionAliases(): void
    {
        [$subjectConnection, $storageConnection] = $this->setUpAliasedPermissionStorage();
        config()->set('permission.models.permission', DynamicAliasedPermission::class);
        $this->flushPermissionState();

        /** @var DatabaseManager $databaseManager */
        $databaseManager = $this->app->make('db');
        $registrar = $this->app->make(PermissionRegistrar::class);
        $committedToken = $databaseManager->usingConnection(
            self::SUBJECT_CONNECTION,
            fn (): string => $registrar->modelAssignmentCacheToken(),
        );
        $subjectProvisionalToken = $databaseManager->usingConnection(
            self::SUBJECT_CONNECTION,
            function () use ($registrar, $subjectConnection): string {
                $subjectConnection->beginTransaction();
                $registrar->rotateModelAssignmentCacheTokenAfterMutation(null);

                return $registrar->modelAssignmentCacheToken();
            },
        );
        $storageProvisionalToken = $databaseManager->usingConnection(
            self::STORAGE_CONNECTION,
            function () use ($registrar, $storageConnection): string {
                $storageConnection->beginTransaction();
                $registrar->rotateModelAssignmentCacheTokenAfterMutation(null);

                return $registrar->modelAssignmentCacheToken();
            },
        );

        $databaseManager->usingConnection(
            self::SUBJECT_CONNECTION,
            fn () => $subjectConnection->commit(),
        );

        $subjectCommittedToken = $databaseManager->usingConnection(
            self::SUBJECT_CONNECTION,
            fn (): string => $registrar->modelAssignmentCacheToken(),
        );
        $storageStillProvisionalToken = $databaseManager->usingConnection(
            self::STORAGE_CONNECTION,
            fn (): string => $registrar->modelAssignmentCacheToken(),
        );

        $this->assertNotSame($committedToken, $subjectProvisionalToken);
        $this->assertNotSame($subjectProvisionalToken, $storageProvisionalToken);
        $this->assertNotSame($subjectProvisionalToken, $subjectCommittedToken);
        $this->assertNotSame($storageProvisionalToken, $subjectCommittedToken);
        $this->assertSame($storageProvisionalToken, $storageStillProvisionalToken);

        $databaseManager->usingConnection(
            self::STORAGE_CONNECTION,
            fn () => $storageConnection->commit(),
        );

        $finalToken = $databaseManager->usingConnection(
            self::SUBJECT_CONNECTION,
            fn (): string => $registrar->modelAssignmentCacheToken(),
        );
        $this->assertNotSame($subjectProvisionalToken, $finalToken);
        $this->assertNotSame($storageProvisionalToken, $finalToken);
        $this->assertNotSame($subjectCommittedToken, $finalToken);
    }

    public function testReverseSyncRotatesBeforeItsFirstPivotMutation(): void
    {
        config()->set('permission.models.role', AssignmentTokenObservingRole::class);
        $this->flushPermissionState();

        $role = AssignmentTokenObservingRole::create(['name' => 'observed-sync-role']);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $committedToken = $registrar->modelAssignmentCacheToken();
        AssignmentTokenObservingPivot::$tokenAtCreation = null;

        $role->syncModels($this->testUser);

        $this->assertIsString(AssignmentTokenObservingPivot::$tokenAtCreation);
        $this->assertNotSame($committedToken, AssignmentTokenObservingPivot::$tokenAtCreation);
        $this->assertNotSame(
            AssignmentTokenObservingPivot::$tokenAtCreation,
            $registrar->modelAssignmentCacheToken(),
        );
    }

    public function testTransactionalSoftDeleteCannotPublishARolledBackCatalog(): void
    {
        $listenerFoundRole = null;

        EarlyDeletedListenerSoftRole::deleted(static function () use (&$listenerFoundRole): void {
            $listenerFoundRole = app(PermissionRegistrar::class)
                ->getRoles(['name' => 'soft-delete-role'])
                ->isNotEmpty();
        });

        $this->setUpAliasedPermissionStorage();
        config()->set('permission.models.role', EarlyDeletedListenerSoftRole::class);
        $this->useSharedPermissionCache();
        $role = EarlyDeletedListenerSoftRole::create(['name' => 'soft-delete-role']);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->getRoles();
        $connection = $role->getConnection();

        $connection->beginTransaction();

        try {
            $role->delete();
        } finally {
            $connection->rollBack();
        }

        $this->assertFalse($listenerFoundRole);

        [$foundRoleId] = parallel([
            fn (): mixed => EarlyDeletedListenerSoftRole::findByName('soft-delete-role')->getKey(),
        ]);

        $this->assertSame($role->getKey(), $foundRoleId);
    }

    public function testSameConnectionHardDeleteInvalidatesTheCatalogOnce(): void
    {
        $coordinator = new CountingPermissionCacheCoordinator;
        $this->app->instance(ModelCacheCoordinator::class, $coordinator);
        $this->app->forgetInstance(PermissionRegistrar::class);

        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->getRoles();
        $coordinator->invalidationCount = 0;

        $this->testUserRole->forceDelete();

        $this->assertSame(1, $coordinator->invalidationCount);
    }

    public function testForwardAndReversePivotMutationsUsePermissionStorageConnection(): void
    {
        [$subjectConnection, $permissionConnection] = $this->setUpAliasedPermissionStorage();
        [$user, $role] = $this->createAliasedPermissionFixtures();

        $permissionConnection->beginTransaction();
        $user->assignRole($role);
        $this->assertSame(1, $permissionConnection->table('model_has_roles')->count());
        $permissionConnection->rollBack();
        $this->assertSame(0, $permissionConnection->table('model_has_roles')->count());

        $permissionConnection->beginTransaction();
        $role->users()->attach($user);
        $this->assertSame(1, $permissionConnection->table('model_has_roles')->count());
        $permissionConnection->rollBack();

        $this->assertSame(0, $permissionConnection->table('model_has_roles')->count());
        $this->assertSame(0, $subjectConnection->table('model_has_roles')->count());
    }

    public function testCustomPermissionPivotUsesPermissionStorageConnection(): void
    {
        $this->setUpAliasedPermissionStorage();
        AliasedPermissionRolePivot::$createdOnConnection = null;
        $user = AliasedCustomPivotPermissionUser::create(['email' => 'custom-pivot@example.com']);
        $role = AliasedPermissionRole::create(['name' => 'custom-pivot-editor']);

        $user->assignRole($role);

        $this->assertSame(self::STORAGE_CONNECTION, AliasedPermissionRolePivot::$createdOnConnection);
    }

    public function testSubjectRollbackDoesNotDeleteCommittedPermissionAssignments(): void
    {
        [$subjectConnection, $permissionConnection] = $this->setUpAliasedPermissionStorage();
        [$user, $role] = $this->createAliasedPermissionFixtures();
        $user->assignRole($role);

        $subjectConnection->beginTransaction();
        $user->delete();

        $this->assertSame(1, $permissionConnection->table('model_has_roles')->count());

        $subjectConnection->rollBack();

        $user = AliasedPermissionUser::findOrFail($user->getKey());
        $this->assertSame(1, $permissionConnection->table('model_has_roles')->count());

        $user->delete();

        $this->assertSame(0, $permissionConnection->table('model_has_roles')->count());
    }

    public function testQueuedAssignmentsRemainAvailableAfterSubjectRollback(): void
    {
        [$subjectConnection, $permissionConnection] = $this->setUpAliasedPermissionStorage();
        [, $role] = $this->createAliasedPermissionFixtures();
        $user = new AliasedPermissionUser(['email' => 'queued@example.com']);
        $user->assignRole($role);

        $subjectConnection->beginTransaction();
        $user->save();
        $this->assertSame(0, $permissionConnection->table('model_has_roles')->count());
        $subjectConnection->rollBack();

        $this->assertSame(0, $permissionConnection->table('model_has_roles')->count());

        $user->exists = false;
        $user->setAttribute($user->getKeyName(), null);

        $subjectConnection->transaction(fn (): bool => $user->save());

        $this->assertSame(1, $permissionConnection->table('model_has_roles')->count());
        $this->assertTrue($user->hasRole($role));
    }

    public function testVetoedStandaloneRoleDeletePreservesRecordAndAssignments(): void
    {
        [, $permissionConnection] = $this->setUpAliasedPermissionStorage();
        [$user, $role] = $this->createAliasedPermissionFixtures();
        $permission = AliasedPermission::create(['name' => 'publish']);
        $user->assignRole($role);
        $role->givePermissionTo($permission);

        AliasedPermissionRole::deleting(static fn (): bool => false);

        $this->assertFalse($role->delete());
        $this->assertSame(0, $permissionConnection->transactionLevel());
        $this->assertSame(1, $permissionConnection->table('roles')->where('id', $role->getKey())->count());
        $this->assertSame(1, $permissionConnection->table('model_has_roles')->count());
        $this->assertSame(1, $permissionConnection->table('role_has_permissions')->count());
    }

    public function testRoleAndPermissionModelWritesRequireTheSameConnectionName(): void
    {
        [, $permissionConnection] = $this->setUpAliasedPermissionStorage();
        config()->set('permission.models.role', MismatchedConnectionRole::class);
        $this->flushPermissionState();

        $caught = null;

        try {
            MismatchedConnectionRole::create(['name' => 'editor']);
        } catch (PermissionConnectionMismatch $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(PermissionConnectionMismatch::class, $caught);
        $this->assertStringContainsString(self::SUBJECT_CONNECTION, $caught->getMessage());
        $this->assertStringContainsString(self::STORAGE_CONNECTION, $caught->getMessage());
        $this->assertSame(0, $permissionConnection->table('roles')->count());
    }

    public function testConnectionMismatchFailsBeforeDeleteSettlementRegistration(): void
    {
        [$subjectConnection, $permissionConnection] = $this->setUpAliasedPermissionStorage();
        config()->set('permission.models.role', MismatchedConnectionRole::class);
        $this->flushPermissionState();

        $user = AliasedPermissionUser::create(['email' => 'subject@example.com']);
        $roleId = $permissionConnection->table('roles')->insertGetId([
            'name' => 'editor',
            'guard_name' => 'web',
        ]);
        $role = MismatchedConnectionRole::findOrFail($roleId);
        $user->assignRole($role);

        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->getRoles();
        $catalogCacheKey = $registrar->getCacheKey();
        $assignmentToken = $registrar->modelAssignmentCacheToken();
        $caught = null;

        $subjectConnection->transaction(function () use ($role, &$caught): void {
            try {
                $role->delete();
            } catch (PermissionConnectionMismatch $exception) {
                $caught = $exception;
            }
        });

        $this->assertInstanceOf(PermissionConnectionMismatch::class, $caught);
        $this->assertSame(1, $permissionConnection->table('roles')->where('id', $roleId)->count());
        $this->assertSame(1, $permissionConnection->table('model_has_roles')->count());
        $this->assertSame($assignmentToken, $registrar->modelAssignmentCacheToken());
        $this->assertTrue($registrar->getCacheRepository()->has($catalogCacheKey));
    }

    public function testRolledBackDeleteSavepointDiscardsItsCacheSettlement(): void
    {
        [, $permissionConnection] = $this->setUpAliasedPermissionStorage();
        [$user, $role] = $this->createAliasedPermissionFixtures();
        $permission = AliasedPermission::create(['name' => 'publish']);
        $user->assignRole($role);
        $role->givePermissionTo($permission);

        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->getRoles();
        $catalogCacheKey = $registrar->getCacheKey();
        $assignmentToken = $registrar->modelAssignmentCacheToken();

        $this->assertTrue($registrar->getCacheRepository()->has($catalogCacheKey));

        $failure = new RuntimeException('Stop after permission cleanup.');
        $caught = null;

        AliasedPermissionRole::deleted(static function () use ($failure): never {
            throw $failure;
        });

        $permissionConnection->transaction(function () use ($permissionConnection, $role, &$caught): void {
            try {
                $role->delete();
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }

            $permissionConnection->table('roles')
                ->where('id', $role->getKey())
                ->update(['name' => 'editor-after-recovery']);
        });

        $this->assertSame($failure, $caught);
        $this->assertSame(1, $permissionConnection->table('roles')->where('id', $role->getKey())->count());
        $this->assertSame(1, $permissionConnection->table('model_has_roles')->count());
        $this->assertSame(1, $permissionConnection->table('role_has_permissions')->count());
        $this->assertSame($assignmentToken, $registrar->modelAssignmentCacheToken());
        $this->assertTrue($registrar->getCacheRepository()->has($catalogCacheKey));
    }

    /**
     * Set up two connection names backed by one permission database.
     *
     * @return array{Connection, Connection}
     */
    private function setUpAliasedPermissionStorage(): array
    {
        $databasePath = $this->temporaryDirectory . '/permissions.sqlite';
        $this->filesystem->put($databasePath, '');
        $connectionConfig = [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];

        config()->set([
            'database.connections.' . self::SUBJECT_CONNECTION => $connectionConfig,
            'database.connections.' . self::STORAGE_CONNECTION => $connectionConfig,
            'permission.models.permission' => AliasedPermission::class,
            'permission.models.role' => AliasedPermissionRole::class,
            'permission.models.default_model' => AliasedPermissionUser::class,
            'auth.providers.users.model' => AliasedPermissionUser::class,
            'cache.stores.permission_shared' => ['driver' => 'worker-array'],
            'permission.cache.store' => 'permission_shared',
        ]);

        /** @var DatabaseManager $databaseManager */
        $databaseManager = $this->app->make('db');
        $subjectConnection = $databaseManager->connection(self::SUBJECT_CONNECTION);
        $permissionConnection = $databaseManager->connection(self::STORAGE_CONNECTION);
        $transactionManager = new DatabaseTransactionsManager;
        $subjectConnection->setTransactionManager($transactionManager);
        $permissionConnection->setTransactionManager($transactionManager);

        $this->createAliasedPermissionSchema($permissionConnection);

        $this->flushPermissionState();

        return [$subjectConnection, $permissionConnection];
    }

    /**
     * Configure a worker-shared cache for transaction visibility assertions.
     */
    private function useSharedPermissionCache(): void
    {
        config()->set([
            'cache.stores.permission_shared' => ['driver' => 'worker-array'],
            'permission.cache.store' => 'permission_shared',
        ]);
        $this->flushPermissionState();
    }

    /**
     * Build an exact role-assignment cache key for an explicit token.
     */
    private function roleAssignmentCacheKey(
        User $user,
        string $token,
    ): string {
        return implode(':', [
            config()->string('permission.cache.keys.model_roles'),
            PermissionPartition::encodeCacheSegment($token),
            PermissionPartition::encodeCacheSegment($user->getMorphClass()),
            PermissionPartition::encodeCacheSegment((string) $user->getKey()),
            PermissionPartition::encodeCacheSegment(null),
        ]);
    }

    /**
     * Create the permission schema through its authoritative connection.
     */
    private function createAliasedPermissionSchema(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();

        $schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });
        $schema->create('permissions', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });
        $schema->create('roles', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['name', 'guard_name']);
        });
        $schema->create('model_has_permissions', static function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_test_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_test_id');
            $table->boolean('is_denied')->default(false);
            $table->primary(['permission_test_id', 'model_test_id', 'model_type']);
        });
        $schema->create('model_has_roles', static function (Blueprint $table): void {
            $table->unsignedBigInteger('role_test_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_test_id');
            $table->primary(['role_test_id', 'model_test_id', 'model_type']);
        });
        $schema->create('role_has_permissions', static function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_test_id');
            $table->unsignedBigInteger('role_test_id');
            $table->boolean('is_denied')->default(false);
            $table->primary(['permission_test_id', 'role_test_id']);
        });
    }

    /**
     * Create one subject and role on the aliased permission database.
     *
     * @return array{AliasedPermissionUser, AliasedPermissionRole}
     */
    private function createAliasedPermissionFixtures(): array
    {
        return [
            AliasedPermissionUser::create(['email' => 'subject@example.com']),
            AliasedPermissionRole::create(['name' => 'editor']),
        ];
    }
}

class AliasedPermissionUser extends UserWithoutHasRoles
{
    use HasRoles;

    protected UnitEnum|string|null $connection = PermissionCacheTransactionTest::SUBJECT_CONNECTION;
}

class AliasedPermissionRole extends Role
{
    protected UnitEnum|string|null $connection = PermissionCacheTransactionTest::STORAGE_CONNECTION;
}

class MismatchedConnectionRole extends Role
{
    protected UnitEnum|string|null $connection = PermissionCacheTransactionTest::SUBJECT_CONNECTION;
}

class EarlyDeletedListenerRole extends Role
{
}

class EarlyDeletedListenerSoftRole extends Role
{
    use SoftDeletes;

    protected UnitEnum|string|null $connection = PermissionCacheTransactionTest::STORAGE_CONNECTION;
}

class AliasedPermission extends Permission
{
    protected UnitEnum|string|null $connection = PermissionCacheTransactionTest::STORAGE_CONNECTION;
}

class DynamicAliasedPermission extends Permission
{
}

class AssignmentTokenObservingRole extends Role
{
    /**
     * Build a reverse assignment relation with an observing pivot.
     */
    protected function relationForModel(
        string $modelClass,
        ?PermissionRelationContext $context = null,
    ): MorphToMany {
        return parent::relationForModel($modelClass, $context)
            ->using(AssignmentTokenObservingPivot::class);
    }
}

class AssignmentTokenObservingPivot extends MorphPivot
{
    public static ?string $tokenAtCreation = null;

    /**
     * Boot the observing pivot model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::created(function (): void {
            static::$tokenAtCreation = app(PermissionRegistrar::class)->modelAssignmentCacheToken();
        });
    }
}

class CountingPermissionCacheCoordinator extends ModelCacheCoordinator
{
    public int $invalidationCount = 0;

    /**
     * Count an exact cache invalidation.
     */
    public function invalidate(CacheRepository $cache, string $key): bool
    {
        ++$this->invalidationCount;

        return parent::invalidate($cache, $key);
    }
}

class AliasedCustomPivotPermissionUser extends AliasedPermissionUser
{
    protected string $guard_name = 'web';

    /**
     * Get the model's assigned roles.
     */
    public function roles(): BelongsToMany
    {
        return parent::roles()->using(AliasedPermissionRolePivot::class);
    }
}

class AliasedPermissionRolePivot extends MorphPivot
{
    public static ?string $createdOnConnection = null;

    /**
     * Boot the custom pivot model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::created(function (self $pivot): void {
            static::$createdOnConnection = $pivot->getConnectionName();
        });
    }
}
