<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\Exceptions\WildcardPermissionInvalidArgument;
use Hypervel\Permission\Exceptions\WildcardPermissionNotImplementsContract;
use Hypervel\Permission\Exceptions\WildcardPermissionNotProperlyFormatted;
use Hypervel\Permission\Models\Permission;
use Hypervel\Tests\Permission\Fixtures\Models\TestRolePermissionsEnum;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\Fixtures\Models\WildcardPermission;
use Hypervel\Tests\Permission\TestCase;

class WildcardHasPermissionsTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('permission.enable_wildcard_permission', true);
    }

    public function testItCanCheckWildcardPermission(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $permission1 = Permission::create(['name' => 'articles.edit,view,create']);
        $permission2 = Permission::create(['name' => 'news.*']);
        $permission3 = Permission::create(['name' => 'posts.*']);

        $user1->givePermissionTo([$permission1, $permission2, $permission3]);

        $this->assertTrue($user1->hasPermissionTo('posts.create'));
        $this->assertTrue($user1->hasPermissionTo('posts.create.123'));
        $this->assertTrue($user1->hasPermissionTo('posts.*'));
        $this->assertTrue($user1->hasPermissionTo('articles.view'));
        $this->assertFalse($user1->hasPermissionTo('projects.view'));
    }

    public function testItCanCheckWildcardPermissionForANonDefaultGuard(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $permission1 = Permission::create(['name' => 'articles.edit,view,create', 'guard_name' => 'api']);
        $permission2 = Permission::create(['name' => 'news.*', 'guard_name' => 'api']);
        $permission3 = Permission::create(['name' => 'posts.*', 'guard_name' => 'api']);

        $user1->givePermissionTo([$permission1, $permission2, $permission3]);

        $this->assertTrue($user1->hasPermissionTo('posts.create', 'api'));
        $this->assertTrue($user1->hasPermissionTo('posts.create.123', 'api'));
        $this->assertTrue($user1->hasPermissionTo('posts.*', 'api'));
        $this->assertTrue($user1->hasPermissionTo('articles.view', 'api'));
        $this->assertFalse($user1->hasPermissionTo('projects.view', 'api'));
    }

    public function testItCanCheckWildcardPermissionFromInstanceWithoutExplicitGuardArgument(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $permission2 = Permission::create(['name' => 'articles.view']);
        $permission1 = Permission::create(['name' => 'articles.edit', 'guard_name' => 'api']);
        $permission3 = Permission::create(['name' => 'news.*', 'guard_name' => 'api']);
        $permission4 = Permission::create(['name' => 'posts.*', 'guard_name' => 'api']);

        $user1->givePermissionTo([$permission1, $permission2, $permission3]);

        $this->assertTrue($user1->hasPermissionTo($permission1));
        $this->assertTrue($user1->hasPermissionTo($permission2));
        $this->assertTrue($user1->hasPermissionTo($permission3));
        $this->assertFalse($user1->hasPermissionTo($permission4));
        $this->assertFalse($user1->hasPermissionTo('articles.edit'));
    }

    public function testItCanAssignWildcardPermissionsUsingEnums(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $articlesCreator = TestRolePermissionsEnum::WildcardArticlesCreator;
        $newsEverything = TestRolePermissionsEnum::WildcardNewsEverything;
        $postsEverything = TestRolePermissionsEnum::WildcardPostsEverything;
        $postsCreate = TestRolePermissionsEnum::WildcardPostsCreate;

        $permission1 = app(Permission::class)->findOrCreate($articlesCreator->value, 'web');
        $permission2 = app(Permission::class)->findOrCreate($newsEverything->value, 'web');
        $permission3 = app(Permission::class)->findOrCreate($postsEverything->value, 'web');

        $user1->givePermissionTo([$permission1, $permission2, $permission3]);

        $this->assertTrue($user1->hasPermissionTo($postsCreate));
        $this->assertTrue($user1->hasPermissionTo($postsCreate->value . '.123'));
        $this->assertTrue($user1->hasPermissionTo($postsEverything));

        $this->assertTrue($user1->hasPermissionTo(TestRolePermissionsEnum::WildcardArticlesView));
        $this->assertTrue($user1->hasAnyPermission(TestRolePermissionsEnum::WildcardArticlesView));

        $this->assertFalse($user1->hasPermissionTo(TestRolePermissionsEnum::WildcardProjectsView));

        $user1->revokePermissionTo([$permission1, $permission2, $permission3]);

        $this->assertFalse($user1->hasPermissionTo(TestRolePermissionsEnum::WildcardPostsCreate));
        $this->assertFalse($user1->hasPermissionTo($postsCreate->value . '.123'));
        $this->assertFalse($user1->hasPermissionTo(TestRolePermissionsEnum::WildcardPostsEverything));

        $this->assertFalse($user1->hasPermissionTo(TestRolePermissionsEnum::WildcardArticlesView));
        $this->assertFalse($user1->hasAnyPermission(TestRolePermissionsEnum::WildcardArticlesView));
    }

    public function testItCanCheckWildcardPermissionsViaRoles(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $user1->assignRole('testRole');

        $permission1 = Permission::create(['name' => 'articles,projects.edit,view,create']);
        $permission2 = Permission::create(['name' => 'news.*.456']);
        $permission3 = Permission::create(['name' => 'posts']);

        $this->testUserRole->givePermissionTo([$permission1, $permission2, $permission3]);

        $this->assertTrue($user1->hasPermissionTo('posts.create'));
        $this->assertTrue($user1->hasPermissionTo('news.create.456'));
        $this->assertTrue($user1->hasPermissionTo('projects.create'));
        $this->assertTrue($user1->hasPermissionTo('articles.view'));
        $this->assertFalse($user1->hasPermissionTo('articles.list'));
        $this->assertFalse($user1->hasPermissionTo('projects.list'));
    }

    public function testItClearsWildcardIndexWhenAssigningARole(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        $permission = Permission::create(['name' => 'posts.*']);
        $this->testUserRole->givePermissionTo($permission);

        // Check permission before assigning role — this populates the wildcard index
        $this->assertFalse($user->hasPermissionTo('posts.create'));

        $user->assignRole('testRole');

        // After assigning the role, the wildcard index should be cleared
        $this->assertTrue($user->hasPermissionTo('posts.create'));
    }

    public function testItClearsWildcardIndexWhenRemovingARole(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        $permission = Permission::create(['name' => 'posts.*']);
        $this->testUserRole->givePermissionTo($permission);

        $user->assignRole('testRole');
        $this->assertTrue($user->hasPermissionTo('posts.create'));

        $user->removeRole('testRole');

        // After removing the role, the wildcard index should be cleared
        $this->assertFalse($user->hasPermissionTo('posts.create'));
    }

    public function testItRebuildsWildcardIndexWhenARoleSyncsItsModels(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        $permission = Permission::create(['name' => 'posts.*']);
        $this->testUserRole->givePermissionTo($permission);

        $user->assignRole('testRole');
        $this->assertTrue($user->hasPermissionTo('posts.create'));

        // syncModels() cannot list the models it removes, so it rotates the assignment token
        // that every wildcard index key includes.
        $this->testUserRole->syncModels([]);

        $this->assertFalse($user->hasPermissionTo('posts.create'));
    }

    public function testItCanCheckCustomWildcardPermission(): void
    {
        config()->set('permission.wildcard_permission', WildcardPermission::class);

        $user1 = User::create(['email' => 'user1@test.com']);

        $permission1 = Permission::create(['name' => 'articles:edit;view;create']);
        $permission2 = Permission::create(['name' => 'news:@']);
        $permission3 = Permission::create(['name' => 'posts:@']);

        $user1->givePermissionTo([$permission1, $permission2, $permission3]);

        $this->assertTrue($user1->hasPermissionTo('posts:create'));
        $this->assertTrue($user1->hasPermissionTo('posts:create:123'));
        $this->assertTrue($user1->hasPermissionTo('posts:@'));
        $this->assertTrue($user1->hasPermissionTo('articles:view'));
        $this->assertFalse($user1->hasPermissionTo('posts.*'));
        $this->assertFalse($user1->hasPermissionTo('articles.view'));
        $this->assertFalse($user1->hasPermissionTo('projects:view'));
    }

    public function testItCanCheckCustomWildcardPermissionsViaRoles(): void
    {
        config()->set('permission.wildcard_permission', WildcardPermission::class);

        $user1 = User::create(['email' => 'user1@test.com']);

        $user1->assignRole('testRole');

        $permission1 = Permission::create(['name' => 'articles;projects:edit;view;create']);
        $permission2 = Permission::create(['name' => 'news:@:456']);
        $permission3 = Permission::create(['name' => 'posts']);

        $this->testUserRole->givePermissionTo([$permission1, $permission2, $permission3]);

        $this->assertTrue($user1->hasPermissionTo('posts:create'));
        $this->assertTrue($user1->hasPermissionTo('news:create:456'));
        $this->assertTrue($user1->hasPermissionTo('projects:create'));
        $this->assertTrue($user1->hasPermissionTo('articles:view'));
        $this->assertFalse($user1->hasPermissionTo('news.create.456'));
        $this->assertFalse($user1->hasPermissionTo('projects.create'));
        $this->assertFalse($user1->hasPermissionTo('articles:list'));
        $this->assertFalse($user1->hasPermissionTo('projects:list'));
    }

    public function testItCanCheckNonWildcardPermissions(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $permission1 = Permission::create(['name' => 'edit articles']);
        $permission2 = Permission::create(['name' => 'create news']);
        $permission3 = Permission::create(['name' => 'update comments']);

        $user1->givePermissionTo([$permission1, $permission2, $permission3]);

        $this->assertTrue($user1->hasPermissionTo('edit articles'));
        $this->assertTrue($user1->hasPermissionTo('create news'));
        $this->assertTrue($user1->hasPermissionTo('update comments'));
    }

    public function testItCanVerifyComplexWildcardPermissions(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $permission1 = Permission::create(['name' => '*.create,update,delete.*.test,course,finance']);
        $permission2 = Permission::create(['name' => 'papers,posts,projects,orders.*.test,test1,test2.*']);
        $permission3 = Permission::create(['name' => 'User::class.create,edit,view']);

        $user1->givePermissionTo([$permission1, $permission2, $permission3]);

        $this->assertTrue($user1->hasPermissionTo('invoices.delete.367463.finance'));
        $this->assertTrue($user1->hasPermissionTo('projects.update.test2.test3'));
        $this->assertTrue($user1->hasPermissionTo('User::class.edit'));
        $this->assertFalse($user1->hasPermissionTo('User::class.delete'));
        $this->assertFalse($user1->hasPermissionTo('User::class.*'));
    }

    public function testItThrowsExceptionWhenWildcardPermissionIsNotProperlyFormatted(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $permission = Permission::create(['name' => '*..']);

        $user1->givePermissionTo([$permission]);

        $this->expectException(WildcardPermissionNotProperlyFormatted::class);

        $user1->hasPermissionTo('invoices.*');
    }

    public function testItThrowsExceptionWhenWildcardPermissionClassDoesNotImplementContract(): void
    {
        config()->set('permission.wildcard_permission', User::class);

        $user1 = User::create(['email' => 'user1@test.com']);

        $this->expectException(WildcardPermissionNotImplementsContract::class);

        $user1->hasPermissionTo('posts.create');
    }

    public function testItThrowsExceptionWhenACommaSeparatedWildcardSubpartIsBlank(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);

        $permission = Permission::create(['name' => 'articles,,edit']);

        $user1->givePermissionTo([$permission]);

        $this->expectException(WildcardPermissionNotProperlyFormatted::class);

        $user1->hasPermissionTo('articles.edit');
    }

    public function testItCanVerifyPermissionInstancesNotAssignedToUser(): void
    {
        $user = User::create(['email' => 'user@test.com']);

        $userPermission = Permission::create(['name' => 'posts.*']);
        $permissionToVerify = Permission::create(['name' => 'posts.create']);

        $user->givePermissionTo([$userPermission]);

        $this->assertTrue($user->hasPermissionTo('posts.create'));
        $this->assertTrue($user->hasPermissionTo('posts.create.123'));
        $this->assertTrue($user->hasPermissionTo($permissionToVerify->id));
        $this->assertTrue($user->hasPermissionTo($permissionToVerify));
    }

    public function testItCanVerifyPermissionInstancesAssignedToUser(): void
    {
        $user = User::create(['email' => 'user@test.com']);

        $userPermission = Permission::create(['name' => 'posts.*']);
        $permissionToVerify = Permission::create(['name' => 'posts.create']);

        $user->givePermissionTo([$userPermission, $permissionToVerify]);

        $this->assertTrue($user->hasPermissionTo('posts.create'));
        $this->assertTrue($user->hasPermissionTo('posts.create.123'));
        $this->assertTrue($user->hasPermissionTo($permissionToVerify));
        $this->assertTrue($user->hasPermissionTo($userPermission));
    }

    public function testItCanVerifyIntegersAsStrings(): void
    {
        $user = User::create(['email' => 'user@test.com']);

        $userPermission = Permission::create(['name' => '8']);

        $user->givePermissionTo([$userPermission]);

        $this->assertTrue($user->hasPermissionTo('8'));
    }

    public function testItThrowsExceptionWhenPermissionHasInvalidArguments(): void
    {
        $user = User::create(['email' => 'user@test.com']);

        $this->expectException(WildcardPermissionInvalidArgument::class);

        $user->hasPermissionTo(['posts.create']);
    }

    public function testItThrowsExceptionWhenPermissionIdNotExists(): void
    {
        $user = User::create(['email' => 'user@test.com']);

        $this->expectException(PermissionDoesNotExist::class);

        $user->hasPermissionTo(6);
    }
}
