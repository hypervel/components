<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;

class CustomGateTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('permission.register_permission_check_method', false);
    }

    public function testItDoesNotRegisterPermissionCheckMethodWhenDisabled(): void
    {
        $this->testUser->givePermissionTo('edit-articles');

        $this->assertSame([], $this->app->make(Gate::class)->abilities());
        $this->assertFalse($this->testUser->can('edit-articles'));
    }

    public function testItCanAuthorizeUsingCustomGateDefinition(): void
    {
        $this->app->make(Gate::class)->define('edit-articles', fn (): bool => true);

        $this->assertArrayHasKey('edit-articles', $this->app->make(Gate::class)->abilities());
        $this->assertTrue($this->testUser->can('edit-articles'));
    }
}
