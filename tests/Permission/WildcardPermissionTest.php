<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Tests\Permission\Fixtures\Models\TestRolePermissionsEnum;
use Hypervel\Tests\Permission\Fixtures\Models\WildcardPermission;

class WildcardPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('config')->set('permission.enable_wildcard_permission', true);
        $this->flushPermissionState();
    }

    public function testItCanCheckWildcardPermissions(): void
    {
        $this->testUser->givePermissionTo([
            Permission::create(['name' => 'articles.edit,view,create']),
            Permission::create(['name' => 'news.*']),
            Permission::create(['name' => 'posts.*']),
        ]);

        $this->assertTrue($this->testUser->hasPermissionTo('posts.create'));
        $this->assertTrue($this->testUser->hasPermissionTo('posts.create.123'));
        $this->assertTrue($this->testUser->hasPermissionTo('posts.*'));
        $this->assertTrue($this->testUser->hasPermissionTo('articles.view'));
        $this->assertFalse($this->testUser->hasPermissionTo('projects.view'));
    }

    public function testItCanCheckWildcardPermissionsViaRoles(): void
    {
        $this->testUser->assignRole('testRole');

        $this->testUserRole->givePermissionTo([
            Permission::create(['name' => 'articles,projects.edit,view,create']),
            Permission::create(['name' => 'news.*.456']),
            Permission::create(['name' => 'posts']),
        ]);

        $this->assertTrue($this->testUser->hasPermissionTo('posts.create'));
        $this->assertTrue($this->testUser->hasPermissionTo('news.create.456'));
        $this->assertTrue($this->testUser->hasPermissionTo('projects.create'));
        $this->assertTrue($this->testUser->hasPermissionTo('articles.view'));
        $this->assertFalse($this->testUser->hasPermissionTo('articles.list'));
        $this->assertFalse($this->testUser->hasPermissionTo('projects.list'));
    }

    public function testItCanAssignWildcardPermissionsUsingEnums(): void
    {
        $this->testUser->givePermissionTo([
            Permission::findOrCreate(TestRolePermissionsEnum::WildcardArticlesCreator),
            Permission::findOrCreate(TestRolePermissionsEnum::WildcardNewsEverything),
            Permission::findOrCreate(TestRolePermissionsEnum::WildcardPostsEverything),
        ]);

        $this->assertTrue($this->testUser->hasPermissionTo(TestRolePermissionsEnum::WildcardPostsCreate));
        $this->assertTrue($this->testUser->hasPermissionTo(TestRolePermissionsEnum::WildcardPostsCreate->value . '.123'));
        $this->assertTrue($this->testUser->hasPermissionTo(TestRolePermissionsEnum::WildcardPostsEverything));
        $this->assertTrue($this->testUser->hasPermissionTo(TestRolePermissionsEnum::WildcardArticlesView));
        $this->assertFalse($this->testUser->hasPermissionTo(TestRolePermissionsEnum::WildcardProjectsView));
    }

    public function testItClearsWildcardIndexWhenAssignmentsChange(): void
    {
        $this->testUserRole->givePermissionTo(Permission::create(['name' => 'posts.*']));

        $this->assertFalse($this->testUser->hasPermissionTo('posts.create'));

        $this->testUser->assignRole('testRole');

        $this->assertTrue($this->testUser->hasPermissionTo('posts.create'));

        $this->testUser->removeRole('testRole');

        $this->assertFalse($this->testUser->hasPermissionTo('posts.create'));
    }

    public function testWildcardIndexUsesCurrentAssignmentCacheVersion(): void
    {
        $this->testUser->givePermissionTo(Permission::create(['name' => 'posts.*']));
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertTrue($this->testUser->hasPermissionTo('posts.create'));

        $this->testUser->getConnection()
            ->table(Config::modelHasPermissionsTable())
            ->where(Config::morphKey(), $this->testUser->getKey())
            ->where('model_type', $this->testUser->getMorphClass())
            ->delete();
        $registrar->bumpModelAssignmentCacheVersion();

        $this->assertFalse($this->testUser->hasPermissionTo('posts.create'));
    }

    public function testItCanUseACustomWildcardPermissionClass(): void
    {
        $this->app->make('config')->set('permission.wildcard_permission', WildcardPermission::class);
        $this->flushPermissionState();

        $this->testUser->givePermissionTo([
            Permission::create(['name' => 'articles:edit;view;create']),
            Permission::create(['name' => 'news:@']),
            Permission::create(['name' => 'posts:@']),
        ]);

        $this->assertTrue($this->testUser->hasPermissionTo('posts:create'));
        $this->assertTrue($this->testUser->hasPermissionTo('posts:create:123'));
        $this->assertTrue($this->testUser->hasPermissionTo('posts:@'));
        $this->assertTrue($this->testUser->hasPermissionTo('articles:view'));
        $this->assertFalse($this->testUser->hasPermissionTo('posts.*'));
        $this->assertFalse($this->testUser->hasPermissionTo('articles.view'));
        $this->assertFalse($this->testUser->hasPermissionTo('projects:view'));
    }
}
