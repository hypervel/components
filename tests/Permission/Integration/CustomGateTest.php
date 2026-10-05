<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Tests\Permission\TestCase;

class CustomGateTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('permission.register_permission_check_method', false);
    }

    public function testItDoesntRegisterTheMethodForCheckingPermissionsOnTheGate(): void
    {
        $this->testUser->givePermissionTo('edit-articles');

        $this->assertEmpty($this->app->make(Gate::class)->abilities());
        $this->assertFalse($this->testUser->can('edit-articles'));
    }

    public function testItCanAuthorizeUsingCustomMethodForCheckingPermissions(): void
    {
        $this->app->make(Gate::class)->define('edit-articles', fn (): bool => true);

        $this->assertArrayHasKey('edit-articles', $this->app->make(Gate::class)->abilities());
        $this->assertTrue($this->testUser->can('edit-articles'));
    }
}
