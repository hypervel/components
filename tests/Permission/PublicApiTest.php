<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Permission\Middleware\PermissionMiddleware;
use Hypervel\Permission\Middleware\RoleMiddleware;
use Hypervel\Permission\Middleware\RoleOrPermissionMiddleware;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Blade;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Permission\Fixtures\Models\TestRolePermissionsEnum;
use Hypervel\View\Compilers\BladeCompiler;
use ReflectionMethod;
use ReflectionParameter;

class PublicApiTest extends TestCase
{
    public function testMiddlewareAliasesAreRegistered(): void
    {
        $router = $this->app->make(Router::class);

        $this->assertSame(RoleMiddleware::class, $router->getMiddleware()['role']);
        $this->assertSame(PermissionMiddleware::class, $router->getMiddleware()['permission']);
        $this->assertSame(RoleOrPermissionMiddleware::class, $router->getMiddleware()['role_or_permission']);
    }

    public function testRouteMacrosAttachPermissionMiddleware(): void
    {
        $roleRoute = Route::get('/roles', $this->getRouteResponse())->role(['testRole', TestRolePermissionsEnum::Editor]);
        $permissionRoute = Route::get('/permissions', $this->getRouteResponse())->permission(['edit-articles', TestRolePermissionsEnum::ViewArticles]);
        $roleOrPermissionRoute = Route::get('/either', $this->getRouteResponse())->roleOrPermission(['testRole', 'edit-articles']);

        $this->assertContains('role:testRole|editor', $roleRoute->middleware());
        $this->assertContains('permission:edit-articles|view articles', $permissionRoute->middleware());
        $this->assertContains('role_or_permission:testRole|edit-articles', $roleOrPermissionRoute->middleware());
    }

    public function testBladeConditionsCheckRolesAndPermissions(): void
    {
        Auth::login($this->testUser);
        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('edit-articles');

        $this->assertTrue(Blade::check('role', 'testRole'));
        $this->assertTrue(Blade::check('hasrole', 'testRole'));
        $this->assertTrue(Blade::check('hasanyrole', ['missing', 'testRole']));
        $this->assertTrue(Blade::check('hasallroles', ['testRole']));
        $this->assertTrue(Blade::check('hasexactroles', ['testRole']));
        $this->assertTrue(Blade::check('haspermission', 'edit-articles'));
    }

    public function testBladeDirectivesCompile(): void
    {
        $compiler = $this->app->make(BladeCompiler::class);

        $compiled = $compiler->compileString("@role('testRole') allowed @else missing @endrole @unlessrole('missing') unless @endunlessrole");

        $this->assertStringContainsString("Blade::check('role', 'testRole')", $compiled);
        $this->assertStringContainsString('<?php else: ?>', $compiled);
        $this->assertStringContainsString("Blade::check('role', 'missing')", $compiled);
        $this->assertStringContainsString('<?php endif; ?>', $compiled);
    }

    public function testPermissionRelationsKeepLaravelBaseReturnTypes(): void
    {
        $this->assertInstanceOf(MorphToMany::class, $this->testUser->roles());
        $this->assertInstanceOf(MorphToMany::class, $this->testUser->permissions());
        $this->assertInstanceOf(BelongsToMany::class, $this->testUserRole->permissions());
        $this->assertInstanceOf(BelongsToMany::class, $this->testUserPermission->roles());
        $this->assertInstanceOf(BelongsToMany::class, $this->testUserRole->users());
        $this->assertInstanceOf(BelongsToMany::class, $this->testUserPermission->users());
        $this->assertInstanceOf(BelongsToMany::class, $this->testUser->teams());

        foreach (['roles', 'permissions', 'teams'] as $relation) {
            $method = new ReflectionMethod($this->testUser, $relation);

            $this->assertSame(BelongsToMany::class, (string) $method->getReturnType());
            $this->assertSame([], $method->getParameters());
        }
    }

    public function testAssignmentMethodsDoNotExposePartitionArguments(): void
    {
        $methods = [
            'assignRole' => ['roles'],
            'removeRole' => ['role'],
            'syncRoles' => ['roles'],
            'givePermissionTo' => ['permissions'],
            'giveForbiddenTo' => ['permissions'],
            'revokePermissionTo' => ['permission'],
            'syncPermissions' => ['permissions'],
            'syncPermissionsWithForbidden' => ['allowed', 'forbidden'],
        ];

        foreach ($methods as $method => $parameters) {
            $reflection = new ReflectionMethod($this->testUser, $method);

            $this->assertSame(
                $parameters,
                array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $reflection->getParameters()),
            );
        }
    }
}
