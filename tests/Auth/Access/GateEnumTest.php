<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Access;

use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Auth\Access\Gate;
use Hypervel\Auth\Middleware\Authorize;
use Hypervel\Tests\Auth\Stub\AccessGateTestAuthenticatable;
use Hypervel\Tests\Auth\Stub\AccessGateTestDummy;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicyWithAllPermissions;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicyWithNoPermissions;
use Hypervel\Tests\TestCase;

enum AbilitiesBackedEnum: string
{
    case ViewDashboard = 'view-dashboard';
    case Update = 'update';
    case Edit = 'edit';
}

enum AbilitiesIntBackedEnum: int
{
    case CreatePost = 1;
    case DeletePost = 2;
}

enum AbilitiesUnitEnum
{
    case ManageUsers;
    case ViewReports;
}

/**
 * @internal
 * @coversNothing
 */
class GateEnumTest extends TestCase
{
    // =========================================================================
    // define() with enums
    // =========================================================================

    public function testDefineWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        // Can check with string (the enum value)
        $this->assertTrue($gate->allows('view-dashboard'));
    }

    public function testDefineWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        // UnitEnum uses ->name, so key is 'ManageUsers'
        $this->assertTrue($gate->allows('ManageUsers'));
    }

    public function testDefineWithIntBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesIntBackedEnum::CreatePost, fn ($user) => true);

        // Int value 1 should be cast to string '1'
        $this->assertTrue($gate->allows('1'));
        $this->assertTrue($gate->allows(AbilitiesIntBackedEnum::CreatePost));
    }

    // =========================================================================
    // allows() with enums
    // =========================================================================

    public function testAllowsWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        $this->assertTrue($gate->allows(AbilitiesBackedEnum::ViewDashboard));
    }

    public function testAllowsWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        $this->assertTrue($gate->allows(AbilitiesUnitEnum::ManageUsers));
    }

    // =========================================================================
    // denies() with enums
    // =========================================================================

    public function testDeniesWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesBackedEnum::ViewDashboard, fn ($user) => false);

        $this->assertTrue($gate->denies(AbilitiesBackedEnum::ViewDashboard));
    }

    public function testDeniesWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesUnitEnum::ManageUsers, fn ($user) => false);

        $this->assertTrue($gate->denies(AbilitiesUnitEnum::ManageUsers));
    }

    // =========================================================================
    // check() with enums (array of abilities)
    // =========================================================================

    public function testCheckWithArrayContainingBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define('allow_1', fn ($user) => true);
        $gate->define('allow_2', fn ($user) => true);
        $gate->define(AbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        $this->assertTrue($gate->check(['allow_1', 'allow_2', AbilitiesBackedEnum::ViewDashboard]));
    }

    public function testCheckWithArrayContainingUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define('allow_1', fn ($user) => true);
        $gate->define(AbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        $this->assertTrue($gate->check(['allow_1', AbilitiesUnitEnum::ManageUsers]));
    }

    // =========================================================================
    // any() with enums
    // =========================================================================

    public function testAnyWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithAllPermissions::class);

        $this->assertTrue($gate->any(['edit', AbilitiesBackedEnum::Update], new AccessGateTestDummy()));
    }

    public function testAnyWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define('deny', fn ($user) => false);
        $gate->define(AbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        $this->assertTrue($gate->any(['deny', AbilitiesUnitEnum::ManageUsers]));
    }

    // =========================================================================
    // none() with enums
    // =========================================================================

    public function testNoneWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithNoPermissions::class);

        $this->assertTrue($gate->none(['edit', AbilitiesBackedEnum::Update], new AccessGateTestDummy()));
    }

    public function testNoneWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define('deny_1', fn ($user) => false);
        $gate->define(AbilitiesUnitEnum::ManageUsers, fn ($user) => false);

        $this->assertTrue($gate->none(['deny_1', AbilitiesUnitEnum::ManageUsers]));
    }

    public function testNoneReturnsFalseWhenAnyAbilityAllows(): void
    {
        $gate = $this->getBasicGate();

        $gate->define('deny', fn ($user) => false);
        $gate->define('allow', fn ($user) => true);

        $this->assertFalse($gate->none(['deny', 'allow']));
    }

    // =========================================================================
    // has() with enums
    // =========================================================================

    public function testHasWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        $this->assertTrue($gate->has(AbilitiesBackedEnum::ViewDashboard));
        $this->assertFalse($gate->has(AbilitiesBackedEnum::Update));
    }

    public function testHasWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        $this->assertTrue($gate->has(AbilitiesUnitEnum::ManageUsers));
        $this->assertFalse($gate->has(AbilitiesUnitEnum::ViewReports));
    }

    public function testHasWithArrayContainingEnums(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesBackedEnum::ViewDashboard, fn ($user) => true);
        $gate->define(AbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        $this->assertTrue($gate->has([AbilitiesBackedEnum::ViewDashboard, AbilitiesUnitEnum::ManageUsers]));
        $this->assertFalse($gate->has([AbilitiesBackedEnum::ViewDashboard, AbilitiesBackedEnum::Update]));
    }

    // =========================================================================
    // authorize() with enums
    // =========================================================================

    public function testAuthorizeWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        $response = $gate->authorize(AbilitiesBackedEnum::ViewDashboard);

        $this->assertTrue($response->allowed());
    }

    public function testAuthorizeWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        $response = $gate->authorize(AbilitiesUnitEnum::ManageUsers);

        $this->assertTrue($response->allowed());
    }

    // =========================================================================
    // inspect() with enums
    // =========================================================================

    public function testInspectWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        $response = $gate->inspect(AbilitiesBackedEnum::ViewDashboard);

        $this->assertTrue($response->allowed());
    }

    public function testInspectWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(AbilitiesUnitEnum::ManageUsers, fn ($user) => false);

        $response = $gate->inspect(AbilitiesUnitEnum::ManageUsers);

        $this->assertFalse($response->allowed());
    }

    // =========================================================================
    // Interoperability tests
    // =========================================================================

    public function testBackedEnumAndStringInteroperability(): void
    {
        $gate = $this->getBasicGate();

        // Define with enum
        $gate->define(AbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        // Check with string (the enum value)
        $this->assertTrue($gate->allows('view-dashboard'));

        // Define with string
        $gate->define('update', fn ($user) => true);

        // Check with enum that has same value
        $this->assertTrue($gate->allows(AbilitiesBackedEnum::Update));
    }

    public function testUnitEnumAndStringInteroperability(): void
    {
        $gate = $this->getBasicGate();

        // Define with enum
        $gate->define(AbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        // Check with string (the enum name)
        $this->assertTrue($gate->allows('ManageUsers'));

        // Define with string
        $gate->define('ViewReports', fn ($user) => true);

        // Check with enum
        $this->assertTrue($gate->allows(AbilitiesUnitEnum::ViewReports));
    }

    // =========================================================================
    // Authorize middleware
    // =========================================================================

    public function testAuthorizeMiddlewareUsingWithBackedEnum(): void
    {
        $result = Authorize::using(AbilitiesBackedEnum::ViewDashboard, 'App\Models\Post');

        $this->assertSame(Authorize::class . ':view-dashboard,App\Models\Post', $result);
    }

    public function testAuthorizeMiddlewareUsingWithUnitEnum(): void
    {
        $result = Authorize::using(AbilitiesUnitEnum::ManageUsers);

        $this->assertSame(Authorize::class . ':ManageUsers', $result);
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    protected function getBasicGate(bool $isGuest = false): Gate
    {
        $container = new Container(new DefinitionSource([]));

        return new Gate(
            $container,
            fn () => $isGuest ? null : new AccessGateTestAuthenticatable()
        );
    }
}
