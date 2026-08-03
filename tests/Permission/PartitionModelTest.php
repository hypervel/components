<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Permission\Exceptions\PermissionPartitionNotResolved;
use Hypervel\Permission\Exceptions\PermissionPartitionViolation;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;
use Hypervel\Tests\Permission\Fixtures\PartitionContext;

class PartitionModelTest extends PartitionTestCase
{
    public function testSameNamesAreIndependentAcrossPartitions(): void
    {
        $roleA = PartitionedRole::create(['name' => 'owner']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);

        $this->setPartition(self::PARTITION_B);

        $roleB = PartitionedRole::create(['name' => 'owner']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);

        $this->assertNotSame($roleA->getKey(), $roleB->getKey());
        $this->assertNotSame($permissionA->getKey(), $permissionB->getKey());
        $this->assertSame(self::PARTITION_B, $roleB->getRawOriginal('workspace_id'));
        $this->assertSame(self::PARTITION_B, $permissionB->getRawOriginal('workspace_id'));

        $this->setPartition(self::PARTITION_A);

        $this->assertSame($roleA->getKey(), PartitionedRole::findByName('owner')->getKey());
        $this->assertSame($permissionA->getKey(), PartitionedPermission::findByName('articles.edit')->getKey());
    }

    public function testOrdinaryQueriesAndCreatesUseTheCurrentPartition(): void
    {
        PartitionedRole::create(['name' => 'role-a']);

        $this->setPartition(self::PARTITION_B);
        PartitionedRole::create(['name' => 'role-b']);

        $this->assertSame(['role-b'], PartitionedRole::query()->pluck('name')->all());

        $this->setPartition(self::PARTITION_A);

        $this->assertSame(['role-a'], PartitionedRole::query()->pluck('name')->all());
    }

    public function testFindByIdAndFindOrCreateUseTheCurrentPartition(): void
    {
        $roleA = PartitionedRole::create(['name' => 'owner']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::findOrCreate('owner');
        $permissionB = PartitionedPermission::findOrCreate('articles.edit');

        $this->assertNotSame($roleA->getKey(), $roleB->getKey());
        $this->assertNotSame($permissionA->getKey(), $permissionB->getKey());
        $this->assertSame($roleB->getKey(), PartitionedRole::findById($roleB->getKey())->getKey());
        $this->assertSame($permissionB->getKey(), PartitionedPermission::findById($permissionB->getKey())->getKey());
    }

    public function testCreateRejectsAConflictingPartitionAttribute(): void
    {
        $this->expectException(PermissionPartitionViolation::class);

        PartitionedRole::create([
            'name' => 'owner',
            'workspace_id' => self::PARTITION_B,
        ]);
    }

    public function testExistingModelCannotChangeItsPartition(): void
    {
        $role = PartitionedRole::create(['name' => 'owner']);
        $role->forceFill(['workspace_id' => self::PARTITION_B]);

        try {
            $role->save();
            $this->fail('Expected an immutable partition change to fail.');
        } catch (PermissionPartitionViolation $exception) {
            $this->assertSame(
                'Permission partition column `workspace_id` is immutable on model `' . PartitionedRole::class . '`; its persisted value `' . self::PARTITION_A . '` cannot be changed to `' . self::PARTITION_B . '`.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            self::PARTITION_A,
            DB::table('roles')->where('id', $role->getKey())->value('workspace_id'),
        );
    }

    public function testMissingContextFailsClosed(): void
    {
        PartitionContext::forget();

        $this->expectException(PermissionPartitionNotResolved::class);

        PartitionedRole::query()->get();
    }

    public function testStaleModelCannotBeSavedInAnotherPartition(): void
    {
        $role = PartitionedRole::create(['name' => 'owner']);
        $this->setPartition(self::PARTITION_B);
        $role->name = 'viewer';

        try {
            $role->save();
            $this->fail('Expected a model from another partition to fail.');
        } catch (PermissionPartitionViolation $exception) {
            $this->assertSame(
                'Model `' . PartitionedRole::class . '` belongs to permission partition `' . self::PARTITION_A . '`, but the current permission partition is `' . self::PARTITION_B . '` for column `workspace_id`.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            ['name' => 'owner', 'workspace_id' => self::PARTITION_A],
            (array) DB::table('roles')
                ->where('id', $role->getKey())
                ->first(['name', 'workspace_id']),
        );
    }

    public function testStaleModelCannotBeSavedQuietlyInAnotherPartition(): void
    {
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $this->setPartition(self::PARTITION_B);
        $permission->name = 'articles.view';

        $this->expectException(PermissionPartitionViolation::class);

        $permission->saveQuietly();
    }

    public function testStaleModelCannotBeDeletedQuietlyInAnotherPartition(): void
    {
        $role = PartitionedRole::create(['name' => 'owner']);
        $this->setPartition(self::PARTITION_B);

        $this->expectException(PermissionPartitionViolation::class);

        $role->deleteQuietly();
    }

    public function testKeylessStaleModelReportsMissingIdentityBeforePartitionMismatch(): void
    {
        Model::preventAccessingMissingAttributes(false);

        $createdRole = PartitionedRole::create(['name' => 'owner']);
        $keylessRole = PartitionedRole::query()
            ->select(['name', 'guard_name', 'workspace_id'])
            ->where('name', 'owner')
            ->firstOrFail();
        $this->setPartition(self::PARTITION_B);

        try {
            $keylessRole->delete();
            $this->fail('Expected a missing role key exception was not thrown.');
        } catch (MissingAttributeException $exception) {
            $this->assertStringContainsString($keylessRole->getKeyName(), $exception->getMessage());
        }

        $this->assertTrue(DB::table('roles')->where('id', $createdRole->getKey())->exists());
    }

    public function testStaleModelCannotBeRefreshedInAnotherPartition(): void
    {
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $this->setPartition(self::PARTITION_B);

        $this->expectException(PermissionPartitionViolation::class);

        $permission->refresh();
    }

    public function testNarrowedModelWithoutPartitionFailsBeforeMutation(): void
    {
        PartitionedRole::create(['name' => 'owner']);
        $role = PartitionedRole::query()->select(['id', 'name'])->firstOrFail();
        $role->name = 'viewer';

        $this->expectException(PermissionPartitionViolation::class);

        $role->save();
    }

    public function testNarrowedModelReportsItsMissingPersistedPartitionBeforeDeletion(): void
    {
        $createdRole = PartitionedRole::create(['name' => 'owner']);
        $role = PartitionedRole::query()->select(['id', 'name'])->firstOrFail();

        try {
            $role->delete();
            $this->fail('Expected a missing persisted partition to fail.');
        } catch (PermissionPartitionViolation $exception) {
            $this->assertSame(
                'Partitioned model `' . PartitionedRole::class . '` has no valid persisted value for permission partition column `workspace_id`; received `null`.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(DB::table('roles')->where('id', $createdRole->getKey())->exists());
    }

    public function testEmptyPersistedPartitionIsRenderedDistinctlyBeforeDeletion(): void
    {
        $role = PartitionedRole::create(['name' => 'owner']);
        $role->setRawAttributes([
            ...$role->getAttributes(),
            'workspace_id' => '',
        ], true);

        try {
            $role->delete();
            $this->fail('Expected an empty persisted partition to fail.');
        } catch (PermissionPartitionViolation $exception) {
            $this->assertSame(
                'Partitioned model `' . PartitionedRole::class . '` has no valid persisted value for permission partition column `workspace_id`; received `\'\' (empty string)`.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(DB::table('roles')->where('id', $role->getKey())->exists());
    }

    public function testNonScalarPersistedPartitionIsRenderedByTypeBeforeDeletion(): void
    {
        $role = PartitionedRole::create(['name' => 'owner']);
        $role->setRawAttributes([
            ...$role->getAttributes(),
            'workspace_id' => [],
        ], true);

        try {
            $role->delete();
            $this->fail('Expected a non-scalar persisted partition to fail.');
        } catch (PermissionPartitionViolation $exception) {
            $this->assertSame(
                'Partitioned model `' . PartitionedRole::class . '` has no valid persisted value for permission partition column `workspace_id`; received `array`.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(DB::table('roles')->where('id', $role->getKey())->exists());
    }

    public function testCreatingListenerCannotReplaceTheCapturedPartition(): void
    {
        PartitionedRole::creating(function (PartitionedRole $role): void {
            $role->setRawAttributes([
                ...$role->getAttributes(),
                'workspace_id' => self::PARTITION_B,
            ]);
        });

        $this->expectException(PermissionPartitionViolation::class);

        PartitionedRole::create(['name' => 'owner']);
    }

    public function testPartitionMutatorCannotReplaceTheCapturedPartition(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setRoleClass(MutatingPartitionedRole::class);

        $this->expectException(PermissionPartitionViolation::class);

        MutatingPartitionedRole::create(['name' => 'owner']);
    }

    public function testInstanceAndBuilderIncrementsRemainPartitionScoped(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->integer('counter')->default(0);
        });

        $roleA = PartitionedRole::create(['name' => 'owner']);
        $roleA->increment('counter', 2);
        $roleA->decrementQuietly('counter');

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'owner']);
        PartitionedRole::query()->increment('counter', 3);

        $this->assertSame(3, $roleB->fresh()->getAttribute('counter'));

        $this->setPartition(self::PARTITION_A);

        $this->assertSame(1, $roleA->fresh()->getAttribute('counter'));
    }

    public function testInstanceIncrementRejectsAConflictingPartitionExtraValue(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->integer('counter')->default(0);
        });

        $role = PartitionedRole::create(['name' => 'owner']);

        $this->expectException(PermissionPartitionViolation::class);

        $role->increment('counter', 1, ['workspace_id' => self::PARTITION_B]);
    }

    public function testModelQueriesContainOnePartitionPredicate(): void
    {
        PartitionedRole::create(['name' => 'owner']);
        DB::enableQueryLog();
        DB::flushQueryLog();

        PartitionedRole::query()->where('name', 'owner')->get();

        $query = DB::getQueryLog()[0];

        $this->assertSame(1, substr_count($query['query'], 'workspace_id'));
        $this->assertSame(['owner', self::PARTITION_A], $query['bindings']);
    }

    public function testSoftDeleteAndQuietRestoreRemainPartitionProtected(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->softDeletes();
        });

        $role = SoftDeletingPartitionedRole::create(['name' => 'owner']);
        $role->delete();

        $this->assertTrue($role->trashed());

        $this->setPartition(self::PARTITION_B);

        try {
            $role->restoreQuietly();
            $this->fail('Expected a stale partition restore to fail.');
        } catch (PermissionPartitionViolation) {
            $this->assertNotNull(DB::table('roles')
                ->where('id', $role->getKey())
                ->value('deleted_at'));
        }

        $this->setPartition(self::PARTITION_A);

        $this->assertTrue($role->restoreQuietly());
        $this->assertFalse($role->trashed());
    }

    public function testCreateThenUpdateUsesSynchronizedOriginalForInvalidation(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);
        $role = PartitionedRole::create(['name' => 'owner']);

        $registrar->getRoles();
        $this->assertTrue($registrar->getCacheRepository()->has($registrar->getCacheKey()));

        $role->name = 'viewer';
        $role->save();

        $this->assertFalse($registrar->getCacheRepository()->has($registrar->getCacheKey()));
        $this->assertSame(self::PARTITION_A, $registrar->partitionFromRecord($role)->value);
    }

    public function testPartitionAccessorCannotChangeCreateInvalidationIdentity(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setRoleClass(AccessorPartitionedRole::class);
        $registrar->getRoles([], false, AccessorPartitionedRole::class);
        $cacheKey = $registrar->getCacheKey();

        $this->assertTrue($registrar->getCacheRepository()->has($cacheKey));

        $role = AccessorPartitionedRole::create(['name' => 'owner']);

        $this->assertSame('accessor:' . self::PARTITION_A, $role->workspace_id);
        $this->assertSame(self::PARTITION_A, $registrar->partitionFromRecord($role)->value);
        $this->assertFalse($registrar->getCacheRepository()->has($cacheKey));
    }
}

class AccessorPartitionedRole extends PartitionedRole
{
    public function getWorkspaceIdAttribute(string $value): string
    {
        return 'accessor:' . $value;
    }
}

class MutatingPartitionedRole extends PartitionedRole
{
    private const string DIFFERENT_PARTITION = '00000000-0000-4000-8000-00000000000b';

    public function setWorkspaceIdAttribute(string $value): void
    {
        $this->attributes['workspace_id'] = self::DIFFERENT_PARTITION;
    }
}

class SoftDeletingPartitionedRole extends PartitionedRole
{
    use SoftDeletes;
}
