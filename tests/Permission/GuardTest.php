<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\Guard;
use Hypervel\Support\Facades\Auth;
use Hypervel\Tests\Permission\Fixtures\Models\Admin;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\Fixtures\PassportGuard;

class GuardTest extends TestCase
{
    public function testZeroGuardNamesRemainStringIdentifiers(): void
    {
        $user = new User;
        $user->setAttribute('guard_name', '0');

        $this->assertSame(['0'], Guard::getNames($user)->all());
        $this->assertSame('0', Guard::getDefaultName($user));

        $this->app->make('config')->set('auth.guards', [
            '0' => ['driver' => 'session', 'provider' => 'users'],
        ]);
        Guard::flushState();

        $this->assertSame(['0'], Guard::getNames(User::class)->all());
        $this->assertSame('0', Guard::getDefaultName(User::class));
    }

    public function testZeroPassportGuardRunsTheClientCompatibilityCheck(): void
    {
        $this->setUpPassport();

        $this->app->make('config')->set([
            'auth.guards.api' => ['driver' => 'session', 'provider' => 'users'],
            'auth.guards.0' => ['driver' => 'passport', 'provider' => 'users'],
        ]);

        $client = $this->testClient;

        Auth::extend('passport', fn (): PassportGuard => new PassportGuard($client));
        Auth::forgetGuards();

        $this->assertNull(Guard::getPassportClient('0'));
    }

    public function testFlushStateClearsCachedGuardMetadata(): void
    {
        $this->assertSame(User::class, Guard::getModelForGuard('web'));

        $this->app->make('config')->set('auth.providers.users.model', Admin::class);

        $this->assertSame(User::class, Guard::getModelForGuard('web'));

        Guard::flushState();

        $this->assertSame(Admin::class, Guard::getModelForGuard('web'));
    }
}
