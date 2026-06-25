<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Middleware;

use Hypervel\Permission\Middleware\PermissionMiddleware;
use Hypervel\Permission\Middleware\RoleMiddleware;
use Hypervel\Permission\Middleware\RoleOrPermissionMiddleware;
use Hypervel\Support\Facades\Auth;
use Hypervel\Tests\Permission\Fixtures\PassportGuard;
use Hypervel\Tests\Permission\TestCase;

class PassportClientMiddlewareTest extends TestCase
{
    public function testPassportClientCanAccessPermissionMiddleware(): void
    {
        $this->setUpPassportClient();

        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(200, $this->runMiddleware($this->app->make(PermissionMiddleware::class), 'edit-posts', null, true));
    }

    public function testPassportClientCanAccessPermissionMiddlewareIfItHasOneOfThePermissions(): void
    {
        $this->setUpPassportClient();

        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(200, $this->runMiddleware($this->app->make(PermissionMiddleware::class), 'edit-news|edit-posts', null, true));
        $this->assertSame(200, $this->runMiddleware($this->app->make(PermissionMiddleware::class), ['edit-news', 'edit-posts'], null, true));
    }

    public function testPassportClientCanAccessPermissionMiddlewareThroughRolePermission(): void
    {
        $this->setUpPassportClient();

        $this->assertSame(403, $this->runMiddleware($this->app->make(PermissionMiddleware::class), 'edit-posts', null, true));

        $this->testClientRole->givePermissionTo('edit-posts');
        $this->testClient->assignRole('clientRole');

        $this->assertSame(200, $this->runMiddleware($this->app->make(PermissionMiddleware::class), 'edit-posts', null, true));
    }

    public function testPassportClientCannotAccessPermissionMiddlewareWithADifferentPermission(): void
    {
        $this->setUpPassportClient();

        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(403, $this->runMiddleware($this->app->make(PermissionMiddleware::class), 'edit-news', null, true));
    }

    public function testPassportClientCannotAccessPermissionMiddlewareWithoutPermissions(): void
    {
        $this->setUpPassportClient();

        $this->assertSame(403, $this->runMiddleware($this->app->make(PermissionMiddleware::class), 'edit-articles|edit-posts', null, true));
    }

    public function testPassportClientCanAccessRoleMiddleware(): void
    {
        $this->setUpPassportClient();

        $this->testClient->assignRole('clientRole');

        $this->assertSame(200, $this->runMiddleware($this->app->make(RoleMiddleware::class), 'clientRole', null, true));
    }

    public function testPassportClientCanAccessRoleMiddlewareIfItHasOneOfTheRoles(): void
    {
        $this->setUpPassportClient();

        $this->testClient->assignRole('clientRole');

        $this->assertSame(200, $this->runMiddleware($this->app->make(RoleMiddleware::class), 'clientRole|testRole2', null, true));
        $this->assertSame(200, $this->runMiddleware($this->app->make(RoleMiddleware::class), ['testRole2', 'clientRole'], null, true));
    }

    public function testPassportClientCannotAccessRoleMiddlewareWithADifferentRole(): void
    {
        $this->setUpPassportClient();

        $this->testClient->assignRole('clientRole');

        $this->assertSame(403, $this->runMiddleware($this->app->make(RoleMiddleware::class), 'clientRole2', null, true));
    }

    public function testPassportClientCannotAccessRoleMiddlewareWithoutRoles(): void
    {
        $this->setUpPassportClient();

        $this->assertSame(403, $this->runMiddleware($this->app->make(RoleMiddleware::class), 'testRole|testRole2', null, true));
    }

    public function testPassportClientCannotAccessRoleMiddlewareWhenRoleIsUndefined(): void
    {
        $this->setUpPassportClient();

        $this->assertSame(403, $this->runMiddleware($this->app->make(RoleMiddleware::class), '', null, true));
    }

    public function testPassportClientCanAccessRoleOrPermissionMiddleware(): void
    {
        $this->setUpPassportClient();

        $this->testClient->assignRole('clientRole');
        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(200, $this->runMiddleware($this->app->make(RoleOrPermissionMiddleware::class), 'clientRole|edit-news|edit-posts', null, true));

        $this->testClient->removeRole('clientRole');

        $this->assertSame(200, $this->runMiddleware($this->app->make(RoleOrPermissionMiddleware::class), 'clientRole|edit-posts', null, true));

        $this->testClient->revokePermissionTo('edit-posts');
        $this->testClient->assignRole('clientRole');

        $this->assertSame(200, $this->runMiddleware($this->app->make(RoleOrPermissionMiddleware::class), 'clientRole|edit-posts', null, true));
        $this->assertSame(200, $this->runMiddleware($this->app->make(RoleOrPermissionMiddleware::class), ['clientRole', 'edit-posts'], null, true));
    }

    public function testPassportClientCannotAccessRoleOrPermissionMiddlewareWithoutTheRoleOrPermission(): void
    {
        $this->setUpPassportClient();

        $this->assertSame(403, $this->runMiddleware($this->app->make(RoleOrPermissionMiddleware::class), 'clientRole|edit-posts', null, true));
        $this->assertSame(403, $this->runMiddleware($this->app->make(RoleOrPermissionMiddleware::class), 'missingRole|missingPermission', null, true));
    }

    public function testPassportClientIsNotUsedWhenFeatureIsDisabled(): void
    {
        $this->setUpPassportClient();

        $this->app->make('config')->set('permission.use_passport_client_credentials', false);

        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(403, $this->runMiddleware($this->app->make(PermissionMiddleware::class), 'edit-posts', 'api', true));
    }

    public function testPassportClientMustMatchRequestedGuard(): void
    {
        $this->setUpPassportClient();

        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(403, $this->runMiddleware($this->app->make(PermissionMiddleware::class), 'edit-posts', 'web', true));
    }

    public function testPassportClientCannotAccessRoleMiddlewareWithWrongGuard(): void
    {
        $this->setUpPassportClient();

        $this->testClient->assignRole('clientRole');

        $this->assertSame(403, $this->runMiddleware($this->app->make(RoleMiddleware::class), 'clientRole', 'admin', true));
    }

    public function testPassportClientCannotAccessRoleOrPermissionMiddlewareWithWrongGuard(): void
    {
        $this->setUpPassportClient();

        $this->testClient->assignRole('clientRole');
        $this->testClient->givePermissionTo('edit-posts');

        $this->assertSame(403, $this->runMiddleware($this->app->make(RoleOrPermissionMiddleware::class), 'edit-posts|clientRole', 'admin', true));
    }

    protected function setUpPassportClient(): void
    {
        $this->setUpPassport();

        $client = $this->testClient;

        Auth::extend('passport', fn (): PassportGuard => new PassportGuard($client));
        Auth::forgetGuards();
    }
}
