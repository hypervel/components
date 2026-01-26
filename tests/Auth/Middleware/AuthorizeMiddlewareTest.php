<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Middleware;

use Hypervel\Auth\Middleware\Authorize;
use PHPUnit\Framework\TestCase;

enum AuthorizeMiddlewareTestBackedEnum: string
{
    case ViewDashboard = 'view-dashboard';
    case ManageUsers = 'manage-users';
}

enum AuthorizeMiddlewareTestIntBackedEnum: int
{
    case CreatePost = 1;
    case DeletePost = 2;
}

enum AuthorizeMiddlewareTestUnitEnum
{
    case ManageUsers;
    case ViewReports;
}

/**
 * @internal
 * @coversNothing
 */
class AuthorizeMiddlewareTest extends TestCase
{
    public function testUsingWithStringAbility(): void
    {
        $result = Authorize::using('view-dashboard');

        $this->assertSame(Authorize::class . ':view-dashboard', $result);
    }

    public function testUsingWithStringAbilityAndModels(): void
    {
        $result = Authorize::using('update', 'App\Models\Post');

        $this->assertSame(Authorize::class . ':update,App\Models\Post', $result);
    }

    public function testUsingWithStringAbilityAndMultipleModels(): void
    {
        $result = Authorize::using('transfer', 'App\Models\Account', 'App\Models\User');

        $this->assertSame(Authorize::class . ':transfer,App\Models\Account,App\Models\User', $result);
    }

    public function testUsingWithBackedEnum(): void
    {
        $result = Authorize::using(AuthorizeMiddlewareTestBackedEnum::ViewDashboard);

        $this->assertSame(Authorize::class . ':view-dashboard', $result);
    }

    public function testUsingWithBackedEnumAndModels(): void
    {
        $result = Authorize::using(AuthorizeMiddlewareTestBackedEnum::ManageUsers, 'App\Models\User');

        $this->assertSame(Authorize::class . ':manage-users,App\Models\User', $result);
    }

    public function testUsingWithUnitEnum(): void
    {
        $result = Authorize::using(AuthorizeMiddlewareTestUnitEnum::ManageUsers);

        $this->assertSame(Authorize::class . ':ManageUsers', $result);
    }

    public function testUsingWithUnitEnumAndModels(): void
    {
        $result = Authorize::using(AuthorizeMiddlewareTestUnitEnum::ViewReports, 'App\Models\Report');

        $this->assertSame(Authorize::class . ':ViewReports,App\Models\Report', $result);
    }

    public function testUsingWithIntBackedEnum(): void
    {
        // Int-backed enum value (1) is used directly - caller should be aware this results in '1' as ability
        $result = Authorize::using(AuthorizeMiddlewareTestIntBackedEnum::CreatePost);

        $this->assertSame(Authorize::class . ':1', $result);
    }
}
