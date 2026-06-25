<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\Exceptions\WildcardPermissionInvalidArgument;
use Hypervel\Permission\Exceptions\WildcardPermissionNotProperlyFormatted;
use Hypervel\Permission\Models\Permission;
use Hypervel\Tests\Permission\Fixtures\Models\TestRolePermissionsEnum;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\Fixtures\Models\WildcardPermission;
use Hypervel\Tests\Permission\TestCase;

class WildcardHasPermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set('permission.enable_wildcard_permission', true);
        $this->flushPermissionState();
    }

    public function testItCanCheckWildcardPermission(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        $user->givePermissionTo([
            Permission::create(['name' => 'articles.edit,view,create']),
            Permission::create(['name' => 'news.*']),
            Permission::create(['name' => 'posts.*']),
        ]);

        $this->assertTrue($user->hasPermissionTo('posts.create'));
        $this->assertTrue($user->hasPermissionTo('posts.create.123'));
        $this->assertTrue($user->hasPermissionTo('posts.*'));
        $this->assertTrue($user->hasPermissionTo('articles.view'));
        $this->assertFalse($user->hasPermissionTo('projects.view'));
    }

    public function testItCanCheckWildcardPermissionForANonDefaultGuard(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        $user->givePermissionTo([
            Permission::create(['name' => 'articles.edit,view,create', 'guard_name' => 'api']),
            Permission::create(['name' => 'news.*', 'guard_name' => 'api']),
            Permission::create(['name' => 'posts.*', 'guard_name' => 'api']),
        ]);

        $this->assertTrue($user->hasPermissionTo('posts.create', 'api'));
        $this->assertTrue($user->hasPermissionTo('posts.create.123', 'api'));
        $this->assertTrue($user->hasPermissionTo('posts.*', 'api'));
        $this->assertTrue($user->hasPermissionTo('articles.view', 'api'));
        $this->assertFalse($user->hasPermissionTo('projects.view', 'api'));
    }

    public function testItCanCheckWildcardPermissionFromInstanceWithoutExplicitGuardArgument(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        $permission1 = Permission::create(['name' => 'articles.edit', 'guard_name' => 'api']);
        $permission2 = Permission::create(['name' => 'articles.view']);
        $permission3 = Permission::create(['name' => 'news.*', 'guard_name' => 'api']);
        $permission4 = Permission::create(['name' => 'posts.*', 'guard_name' => 'api']);

        $user->givePermissionTo([$permission1, $permission2, $permission3]);

        $this->assertTrue($user->hasPermissionTo($permission1));
        $this->assertTrue($user->hasPermissionTo($permission2));
        $this->assertTrue($user->hasPermissionTo($permission3));
        $this->assertFalse($user->hasPermissionTo($permission4));
        $this->assertFalse($user->hasPermissionTo('articles.edit'));
    }

    public function testItCanAssignWildcardPermissionsUsingEnums(): void
    {
        $articlesCreator = TestRolePermissionsEnum::WildcardArticlesCreator;
        $newsEverything = TestRolePermissionsEnum::WildcardNewsEverything;
        $postsEverything = TestRolePermissionsEnum::WildcardPostsEverything;
        $postsCreate = TestRolePermissionsEnum::WildcardPostsCreate;

        $user = User::create(['email' => 'user1@test.com']);
        $user->givePermissionTo([
            Permission::findOrCreate($articlesCreator),
            Permission::findOrCreate($newsEverything),
            Permission::findOrCreate($postsEverything),
        ]);

        $this->assertTrue($user->hasPermissionTo($postsCreate));
        $this->assertTrue($user->hasPermissionTo($postsCreate->value . '.123'));
        $this->assertTrue($user->hasPermissionTo($postsEverything));
        $this->assertTrue($user->hasPermissionTo(TestRolePermissionsEnum::WildcardArticlesView));
        $this->assertTrue($user->hasAnyPermission(TestRolePermissionsEnum::WildcardArticlesView));
        $this->assertFalse($user->hasPermissionTo(TestRolePermissionsEnum::WildcardProjectsView));

        $user->revokePermissionTo([$articlesCreator, $newsEverything, $postsEverything]);

        $this->assertFalse($user->hasPermissionTo($postsCreate));
        $this->assertFalse($user->hasPermissionTo($postsCreate->value . '.123'));
        $this->assertFalse($user->hasPermissionTo($postsEverything));
        $this->assertFalse($user->hasPermissionTo(TestRolePermissionsEnum::WildcardArticlesView));
        $this->assertFalse($user->hasAnyPermission(TestRolePermissionsEnum::WildcardArticlesView));
    }

    public function testItCanCheckWildcardPermissionsViaRoles(): void
    {
        $user = User::create(['email' => 'user1@test.com']);
        $user->assignRole('testRole');

        $this->testUserRole->givePermissionTo([
            Permission::create(['name' => 'articles,projects.edit,view,create']),
            Permission::create(['name' => 'news.*.456']),
            Permission::create(['name' => 'posts']),
        ]);

        $this->assertTrue($user->hasPermissionTo('posts.create'));
        $this->assertTrue($user->hasPermissionTo('news.create.456'));
        $this->assertTrue($user->hasPermissionTo('projects.create'));
        $this->assertTrue($user->hasPermissionTo('articles.view'));
        $this->assertFalse($user->hasPermissionTo('articles.list'));
        $this->assertFalse($user->hasPermissionTo('projects.list'));
    }

    public function testItCanCheckCustomWildcardPermission(): void
    {
        $this->app->make('config')->set('permission.wildcard_permission', WildcardPermission::class);
        $this->flushPermissionState();

        $user = User::create(['email' => 'user1@test.com']);
        $user->givePermissionTo([
            Permission::create(['name' => 'articles:edit;view;create']),
            Permission::create(['name' => 'news:@']),
            Permission::create(['name' => 'posts:@']),
        ]);

        $this->assertTrue($user->hasPermissionTo('posts:create'));
        $this->assertTrue($user->hasPermissionTo('posts:create:123'));
        $this->assertTrue($user->hasPermissionTo('posts:@'));
        $this->assertTrue($user->hasPermissionTo('articles:view'));
        $this->assertFalse($user->hasPermissionTo('posts.*'));
        $this->assertFalse($user->hasPermissionTo('articles.view'));
        $this->assertFalse($user->hasPermissionTo('projects:view'));
    }

    public function testItCanCheckCustomWildcardPermissionsViaRoles(): void
    {
        $this->app->make('config')->set('permission.wildcard_permission', WildcardPermission::class);
        $this->flushPermissionState();

        $user = User::create(['email' => 'user1@test.com']);
        $user->assignRole('testRole');

        $this->testUserRole->givePermissionTo([
            Permission::create(['name' => 'articles;projects:edit;view;create']),
            Permission::create(['name' => 'news:@:456']),
            Permission::create(['name' => 'posts']),
        ]);

        $this->assertTrue($user->hasPermissionTo('posts:create'));
        $this->assertTrue($user->hasPermissionTo('news:create:456'));
        $this->assertTrue($user->hasPermissionTo('projects:create'));
        $this->assertTrue($user->hasPermissionTo('articles:view'));
        $this->assertFalse($user->hasPermissionTo('news.create.456'));
        $this->assertFalse($user->hasPermissionTo('projects.create'));
        $this->assertFalse($user->hasPermissionTo('articles:list'));
        $this->assertFalse($user->hasPermissionTo('projects:list'));
    }

    public function testItCanCheckNonWildcardPermissions(): void
    {
        $user = User::create(['email' => 'user1@test.com']);
        $user->givePermissionTo([
            Permission::create(['name' => 'edit articles']),
            Permission::create(['name' => 'create news']),
            Permission::create(['name' => 'update comments']),
        ]);

        $this->assertTrue($user->hasPermissionTo('edit articles'));
        $this->assertTrue($user->hasPermissionTo('create news'));
        $this->assertTrue($user->hasPermissionTo('update comments'));
    }

    public function testItCanVerifyComplexWildcardPermissions(): void
    {
        $user = User::create(['email' => 'user1@test.com']);
        $user->givePermissionTo([
            Permission::create(['name' => '*.create,update,delete.*.test,course,finance']),
            Permission::create(['name' => 'papers,posts,projects,orders.*.test,test1,test2.*']),
            Permission::create(['name' => 'User::class.create,edit,view']),
        ]);

        $this->assertTrue($user->hasPermissionTo('invoices.delete.367463.finance'));
        $this->assertTrue($user->hasPermissionTo('projects.update.test2.test3'));
        $this->assertTrue($user->hasPermissionTo('User::class.edit'));
        $this->assertFalse($user->hasPermissionTo('User::class.delete'));
        $this->assertFalse($user->hasPermissionTo('User::class.*'));
    }

    public function testItThrowsExceptionWhenWildcardPermissionIsNotProperlyFormatted(): void
    {
        $user = User::create(['email' => 'user1@test.com']);
        $user->givePermissionTo(Permission::create(['name' => '*..']));

        $this->expectException(WildcardPermissionNotProperlyFormatted::class);
        $user->hasPermissionTo('invoices.*');
    }

    public function testItCanVerifyPermissionInstancesNotAssignedToUser(): void
    {
        $user = User::create(['email' => 'user@test.com']);

        $userPermission = Permission::create(['name' => 'posts.*']);
        $permissionToVerify = Permission::create(['name' => 'posts.create']);

        $user->givePermissionTo($userPermission);

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
        $user->givePermissionTo(Permission::create(['name' => '8']));

        $this->assertTrue($user->hasPermissionTo('8'));
    }

    public function testItThrowsExceptionWhenPermissionHasInvalidArguments(): void
    {
        $user = User::create(['email' => 'user@test.com']);

        $this->expectException(WildcardPermissionInvalidArgument::class);
        $user->hasPermissionTo(['posts.create']);
    }

    public function testItThrowsExceptionWhenPermissionIdDoesNotExist(): void
    {
        $user = User::create(['email' => 'user@test.com']);

        $this->expectException(PermissionDoesNotExist::class);
        $user->hasPermissionTo(6);
    }
}
