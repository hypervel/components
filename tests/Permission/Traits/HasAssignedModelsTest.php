<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Permission\Fixtures\Models\SoftDeletingUser;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class HasAssignedModelsTest extends TestCase
{
    public function testItCanSyncModelsToARole(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        $this->testUserRole->syncModels([$user1, $user2]);

        $this->assertTrue($user1->fresh()->hasRole($this->testUserRole));
        $this->assertTrue($user2->fresh()->hasRole($this->testUserRole));
    }

    public function testItRemovesPreviousModelsWhenSyncing(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        $user1->assignRole($this->testUserRole);
        $this->assertTrue($user1->fresh()->hasRole($this->testUserRole));

        $this->testUserRole->syncModels([$user2]);

        $this->assertFalse($user1->fresh()->hasRole($this->testUserRole));
        $this->assertTrue($user2->fresh()->hasRole($this->testUserRole));
    }

    public function testItRemovesAllModelsWhenSyncingWithAnEmptyArray(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        $user1->assignRole($this->testUserRole);
        $user2->assignRole($this->testUserRole);

        $this->testUserRole->syncModels([]);

        $this->assertFalse($user1->fresh()->hasRole($this->testUserRole));
        $this->assertFalse($user2->fresh()->hasRole($this->testUserRole));
    }

    public function testItDoesNotAddDuplicateModelsWhenSyncing(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $this->testUserRole->syncModels([$user1, $user1]);

        $count = DB::table(Config::modelHasRolesTable())
            ->where(app(PermissionRegistrar::class)->pivotRole, $this->testUserRole->getKey())
            ->count();

        $this->assertSame(1, $count);
    }

    public function testItCanSyncModelsUsingASingleModelInstance(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $this->testUserRole->syncModels($user1);

        $this->assertTrue($user1->fresh()->hasRole($this->testUserRole));
    }

    public function testItCanSyncModelsUsingIds(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        $this->testUserRole->syncModels([$user1->getKey(), $user2->getKey()]);

        $this->assertTrue($user1->fresh()->hasRole($this->testUserRole));
        $this->assertTrue($user2->fresh()->hasRole($this->testUserRole));
    }

    public function testItCanAssignARoleToModels(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        $this->testUserRole->assignToModels([$user1, $user2]);

        $this->assertTrue($user1->fresh()->hasRole($this->testUserRole));
        $this->assertTrue($user2->fresh()->hasRole($this->testUserRole));
        $this->assertTrue($this->testUserRole->users->contains($user1));
        $this->assertTrue($this->testUserRole->users->contains($user2));
    }

    public function testItCanAssignARoleToASingleModelInstance(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $this->testUserRole->assignToModels($user1);

        $this->assertTrue($user1->fresh()->hasRole($this->testUserRole));
    }

    public function testItCanAssignARoleToModelsUsingIds(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $this->testUserRole->assignToModels($user1->getKey());

        $this->assertTrue($user1->fresh()->hasRole($this->testUserRole));
    }

    public function testItDoesNotAssignDuplicateModels(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $this->testUserRole->assignToModels([$user1, $user1]);

        $count = DB::table(Config::modelHasRolesTable())
            ->where(app(PermissionRegistrar::class)->pivotRole, $this->testUserRole->getKey())
            ->count();

        $this->assertSame(1, $count);
    }

    public function testItDoesNotReAssignModelsAlreadyAssigned(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $this->testUserRole->assignToModels($user1);
        $this->testUserRole->assignToModels($user1);

        $count = DB::table(Config::modelHasRolesTable())
            ->where(app(PermissionRegistrar::class)->pivotRole, $this->testUserRole->getKey())
            ->count();

        $this->assertSame(1, $count);
    }

    public function testItDoesNotReAssignASoftDeletedModelWithAnExistingPivot(): void
    {
        $user = SoftDeletingUser::create(['email' => 'user@test.com']);

        $this->testUserRole->assignToModels($user);
        $user->delete();

        $this->testUserRole->assignToModels($user);

        $count = DB::table(Config::modelHasRolesTable())
            ->where(app(PermissionRegistrar::class)->pivotRole, $this->testUserRole->getKey())
            ->where('model_type', $user->getMorphClass())
            ->where(Config::morphKey(), $user->getKey())
            ->count();

        $this->assertSame(1, $count);
    }

    public function testItCanAssignAdditionalModelsWithoutRemovingExistingOnes(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        $this->testUserRole->assignToModels($user1);
        $this->testUserRole->assignToModels($user2);

        $this->assertTrue($user1->fresh()->hasRole($this->testUserRole));
        $this->assertTrue($user2->fresh()->hasRole($this->testUserRole));
    }

    public function testItCanRemoveARoleFromModels(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        $user1->assignRole($this->testUserRole);
        $user2->assignRole($this->testUserRole);

        $this->testUserRole->removeFromModels([$user1]);

        $this->assertFalse($user1->fresh()->hasRole($this->testUserRole));
        $this->assertTrue($user2->fresh()->hasRole($this->testUserRole));
    }

    public function testItCanRemoveARoleFromASingleModelInstance(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $user1->assignRole($this->testUserRole);

        $this->testUserRole->removeFromModels($user1);

        $this->assertFalse($user1->fresh()->hasRole($this->testUserRole));
    }

    public function testItCanRemoveARoleFromModelsUsingIds(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $user1->assignRole($this->testUserRole);

        $this->testUserRole->removeFromModels($user1->getKey());

        $this->assertFalse($user1->fresh()->hasRole($this->testUserRole));
    }

    #[DataProvider('reverseAssignmentProvider')]
    public function testReverseAssignmentsRejectKeylessModelInputs(string $method, bool $mixed): void
    {
        Model::preventAccessingMissingAttributes(false);

        $assignedUser = User::create(['email' => 'assigned@test.com']);
        $otherUser = User::create(['email' => 'other@test.com']);
        $assignedUser->assignRole($this->testUserRole);
        $keylessUser = User::query()->select('email')->where('email', 'other@test.com')->firstOrFail();
        $models = $mixed ? [$assignedUser, $keylessUser] : $keylessUser;

        try {
            $this->testUserRole->{$method}($models);
            $this->fail('Expected a missing assigned-model key exception was not thrown.');
        } catch (MissingAttributeException $exception) {
            $this->assertStringContainsString($keylessUser->getKeyName(), $exception->getMessage());
        }

        $this->assertTrue($assignedUser->fresh()->hasRole($this->testUserRole));
        $this->assertFalse($otherUser->fresh()->hasRole($this->testUserRole));
    }

    public static function reverseAssignmentProvider(): array
    {
        return [
            'assign keyless model' => ['assignToModels', false],
            'assign mixed models' => ['assignToModels', true],
            'remove keyless model' => ['removeFromModels', false],
            'remove mixed models' => ['removeFromModels', true],
            'sync keyless model' => ['syncModels', false],
            'sync mixed models' => ['syncModels', true],
        ];
    }

    public function testItDoesNothingWhenRemovingTheRoleFromModelsThatDoNotHaveIt(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        $user1->assignRole($this->testUserRole);

        $this->testUserRole->removeFromModels($user2);

        $this->assertTrue($user1->fresh()->hasRole($this->testUserRole));
    }

    public function testItCanSyncModelsUsingIdsWithExplicitModelClass(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        $this->testUserRole->syncModels([$user1->getKey(), $user2->getKey()], User::class);

        $this->assertTrue($user1->fresh()->hasRole($this->testUserRole));
        $this->assertTrue($user2->fresh()->hasRole($this->testUserRole));
    }

    public function testItCanAssignARoleToModelsUsingIdsWithExplicitModelClass(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $this->testUserRole->assignToModels($user1->getKey(), User::class);

        $this->assertTrue($user1->fresh()->hasRole($this->testUserRole));
    }

    public function testItCanRemoveARoleFromModelsUsingIdsWithExplicitModelClass(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $user1->assignRole($this->testUserRole);

        $this->testUserRole->removeFromModels($user1->getKey(), User::class);

        $this->assertFalse($user1->fresh()->hasRole($this->testUserRole));
    }

    public function testItUsesConfigDefaultModelWhenResolvingIds(): void
    {
        config()->set('permission.models.default_model', User::class);

        $user1 = User::create(['email' => 'user1@test.com']);

        $this->testUserRole->syncModels([$user1->getKey()]);

        $this->assertTrue($user1->fresh()->hasRole($this->testUserRole));
    }

    public function testUnsavedRoleReverseAssignmentsAreQueryFreeFluentNoOps(): void
    {
        $user = User::create(['email' => 'user@test.com']);
        $role = $this->testUserRole->newInstance([
            'name' => 'unsaved',
            'guard_name' => $this->testUserRole->guard_name,
        ]);
        $registrar = app(PermissionRegistrar::class);
        $token = $registrar->modelAssignmentCacheToken();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame($role, $role->assignToModels($user));
        $this->assertSame($role, $role->removeFromModels($user));
        $this->assertSame($role, $role->syncModels([$user]));
        $this->assertSame([], DB::getQueryLog());
        $this->assertSame($token, $registrar->modelAssignmentCacheToken());

        $role->save();

        $this->assertSame(0, DB::table(Config::modelHasRolesTable())
            ->where($registrar->pivotRole, $role->getKey())
            ->count());
    }

    #[DataProvider('reverseAssignmentOwnerProvider')]
    public function testReverseAssignmentsRejectAKeylessPersistedRoleBeforeMutation(string $method): void
    {
        Model::preventAccessingMissingAttributes(false);

        $assignedUser = User::create(['email' => 'assigned@test.com']);
        $replacementUser = User::create(['email' => 'replacement@test.com']);
        $assignedUser->assignRole($this->testUserRole);
        $keylessRole = $this->testUserRole->newQuery()
            ->select(['name', 'guard_name'])
            ->where('name', $this->testUserRole->name)
            ->firstOrFail();

        $models = $method === 'removeFromModels' ? [$assignedUser] : [$replacementUser];

        try {
            $keylessRole->{$method}($models);
            $this->fail('Expected a missing role key exception was not thrown.');
        } catch (MissingAttributeException $exception) {
            $this->assertStringContainsString($keylessRole->getKeyName(), $exception->getMessage());
        }

        $this->assertTrue($assignedUser->fresh()->hasRole($this->testUserRole));
        $this->assertFalse($replacementUser->fresh()->hasRole($this->testUserRole));
    }

    public static function reverseAssignmentOwnerProvider(): array
    {
        return [
            'assign models' => ['assignToModels'],
            'remove models' => ['removeFromModels'],
            'sync models' => ['syncModels'],
        ];
    }
}
