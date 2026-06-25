<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;

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
}
