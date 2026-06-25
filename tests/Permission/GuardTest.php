<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\Guard;
use Hypervel\Tests\Permission\Fixtures\Models\Admin;
use Hypervel\Tests\Permission\Fixtures\Models\User;

class GuardTest extends TestCase
{
    public function testFlushStateClearsCachedGuardMetadata(): void
    {
        $this->assertSame(User::class, Guard::getModelForGuard('web'));

        $this->app->make('config')->set('auth.providers.users.model', Admin::class);

        $this->assertSame(User::class, Guard::getModelForGuard('web'));

        Guard::flushState();

        $this->assertSame(Admin::class, Guard::getModelForGuard('web'));
    }
}
