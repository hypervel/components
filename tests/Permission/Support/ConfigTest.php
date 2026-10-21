<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Support;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Permission\PermissionServiceProvider;
use Hypervel\Permission\Support\Config;
use Hypervel\Permission\WildcardPermission;
use Hypervel\Support\Facades\Auth;
use Hypervel\Testbench\TestCase;

class ConfigTest extends TestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [PermissionServiceProvider::class];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set([
            'auth.defaults.guard' => 'web',
            'auth.guards.web' => [
                'driver' => 'session',
                'provider' => 'users',
            ],
            'auth.guards.admin' => [
                'driver' => 'session',
                'provider' => 'admins',
            ],
        ]);
    }

    public function testDefaultGuardFallsBackToConfig(): void
    {
        $this->assertSame('web', Config::defaultGuard());
    }

    public function testDefaultGuardFollowsCurrentGuard(): void
    {
        Auth::shouldUse('admin');

        $this->assertSame('admin', Config::defaultGuard());
    }

    public function testWildcardPermissionClassUsesItsDefaultWhenOmitted(): void
    {
        $permissionConfig = config()->array('permission');
        unset($permissionConfig['wildcard_permission']);
        config()->set('permission', $permissionConfig);

        $this->assertSame(WildcardPermission::class, Config::wildcardPermissionClass());
    }
}
