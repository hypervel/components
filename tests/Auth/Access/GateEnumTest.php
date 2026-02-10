<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Access;

use Hypervel\Auth\Access\Gate;
use Hypervel\Container\Container;
use Hypervel\Tests\Auth\Stub\AccessGateTestAuthenticatable;
use Hypervel\Tests\Auth\Stub\AccessGateTestDummy;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicyWithAllPermissions;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicyWithNoPermissions;
use Hypervel\Tests\TestCase;
use TypeError;

enum GateEnumTestAbilitiesBackedEnum: string
{
    case ViewDashboard = 'view-dashboard';
    case Update = 'update';
    case Edit = 'edit';
}

enum GateEnumTestAbilitiesIntBackedEnum: int
{
    case CreatePost = 1;
    case DeletePost = 2;
}

enum GateEnumTestAbilitiesUnitEnum
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

        $gate->define(GateEnumTestAbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        // Can check with string (the enum value)
        $this->assertTrue($gate->allows('view-dashboard'));
    }

    public function testDefineWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(GateEnumTestAbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        // UnitEnum uses ->name, so key is 'ManageUsers'
        $this->assertTrue($gate->allows('ManageUsers'));
    }

    public function testDefineWithIntBackedEnumStoresUnderIntKey(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(GateEnumTestAbilitiesIntBackedEnum::CreatePost, fn ($user) => true);

        // Int value 1 is used as ability key - can check with string '1'
        $this->assertTrue($gate->allows('1'));
    }

    public function testAllowsWithIntBackedEnumThrowsTypeError(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(GateEnumTestAbilitiesIntBackedEnum::CreatePost, fn ($user) => true);

        // Int-backed enum causes TypeError because raw() expects string
        $this->expectException(TypeError::class);
        $gate->allows(GateEnumTestAbilitiesIntBackedEnum::CreatePost);
    }

    // =========================================================================
    // allows() with enums
    // =========================================================================

    public function testAllowsWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(GateEnumTestAbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        $this->assertTrue($gate->allows(GateEnumTestAbilitiesBackedEnum::ViewDashboard));
    }

    public function testAllowsWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(GateEnumTestAbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        $this->assertTrue($gate->allows(GateEnumTestAbilitiesUnitEnum::ManageUsers));
    }

    // =========================================================================
    // denies() with enums
    // =========================================================================

    public function testDeniesWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(GateEnumTestAbilitiesBackedEnum::ViewDashboard, fn ($user) => false);

        $this->assertTrue($gate->denies(GateEnumTestAbilitiesBackedEnum::ViewDashboard));
    }

    public function testDeniesWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(GateEnumTestAbilitiesUnitEnum::ManageUsers, fn ($user) => false);

        $this->assertTrue($gate->denies(GateEnumTestAbilitiesUnitEnum::ManageUsers));
    }

    // =========================================================================
    // check() with enums (array of abilities)
    // =========================================================================

    public function testCheckWithArrayContainingBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define('allow_1', fn ($user) => true);
        $gate->define('allow_2', fn ($user) => true);
        $gate->define(GateEnumTestAbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        $this->assertTrue($gate->check(['allow_1', 'allow_2', GateEnumTestAbilitiesBackedEnum::ViewDashboard]));
    }

    public function testCheckWithArrayContainingUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define('allow_1', fn ($user) => true);
        $gate->define(GateEnumTestAbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        $this->assertTrue($gate->check(['allow_1', GateEnumTestAbilitiesUnitEnum::ManageUsers]));
    }

    // =========================================================================
    // any() with enums
    // =========================================================================

    public function testAnyWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithAllPermissions::class);

        $this->assertTrue($gate->any(['edit', GateEnumTestAbilitiesBackedEnum::Update], new AccessGateTestDummy()));
    }

    public function testAnyWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define('deny', fn ($user) => false);
        $gate->define(GateEnumTestAbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        $this->assertTrue($gate->any(['deny', GateEnumTestAbilitiesUnitEnum::ManageUsers]));
    }

    // =========================================================================
    // none() with enums
    // =========================================================================

    public function testNoneWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithNoPermissions::class);

        $this->assertTrue($gate->none(['edit', GateEnumTestAbilitiesBackedEnum::Update], new AccessGateTestDummy()));
    }

    public function testNoneWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define('deny_1', fn ($user) => false);
        $gate->define(GateEnumTestAbilitiesUnitEnum::ManageUsers, fn ($user) => false);

        $this->assertTrue($gate->none(['deny_1', GateEnumTestAbilitiesUnitEnum::ManageUsers]));
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

        $gate->define(GateEnumTestAbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        $this->assertTrue($gate->has(GateEnumTestAbilitiesBackedEnum::ViewDashboard));
        $this->assertFalse($gate->has(GateEnumTestAbilitiesBackedEnum::Update));
    }

    public function testHasWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(GateEnumTestAbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        $this->assertTrue($gate->has(GateEnumTestAbilitiesUnitEnum::ManageUsers));
        $this->assertFalse($gate->has(GateEnumTestAbilitiesUnitEnum::ViewReports));
    }

    public function testHasWithArrayContainingEnums(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(GateEnumTestAbilitiesBackedEnum::ViewDashboard, fn ($user) => true);
        $gate->define(GateEnumTestAbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        $this->assertTrue($gate->has([GateEnumTestAbilitiesBackedEnum::ViewDashboard, GateEnumTestAbilitiesUnitEnum::ManageUsers]));
        $this->assertFalse($gate->has([GateEnumTestAbilitiesBackedEnum::ViewDashboard, GateEnumTestAbilitiesBackedEnum::Update]));
    }

    // =========================================================================
    // authorize() with enums
    // =========================================================================

    public function testAuthorizeWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(GateEnumTestAbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        $response = $gate->authorize(GateEnumTestAbilitiesBackedEnum::ViewDashboard);

        $this->assertTrue($response->allowed());
    }

    public function testAuthorizeWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(GateEnumTestAbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        $response = $gate->authorize(GateEnumTestAbilitiesUnitEnum::ManageUsers);

        $this->assertTrue($response->allowed());
    }

    // =========================================================================
    // inspect() with enums
    // =========================================================================

    public function testInspectWithBackedEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(GateEnumTestAbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        $response = $gate->inspect(GateEnumTestAbilitiesBackedEnum::ViewDashboard);

        $this->assertTrue($response->allowed());
    }

    public function testInspectWithUnitEnum(): void
    {
        $gate = $this->getBasicGate();

        $gate->define(GateEnumTestAbilitiesUnitEnum::ManageUsers, fn ($user) => false);

        $response = $gate->inspect(GateEnumTestAbilitiesUnitEnum::ManageUsers);

        $this->assertFalse($response->allowed());
    }

    // =========================================================================
    // Interoperability tests
    // =========================================================================

    public function testBackedEnumAndStringInteroperability(): void
    {
        $gate = $this->getBasicGate();

        // Define with enum
        $gate->define(GateEnumTestAbilitiesBackedEnum::ViewDashboard, fn ($user) => true);

        // Check with string (the enum value)
        $this->assertTrue($gate->allows('view-dashboard'));

        // Define with string
        $gate->define('update', fn ($user) => true);

        // Check with enum that has same value
        $this->assertTrue($gate->allows(GateEnumTestAbilitiesBackedEnum::Update));
    }

    public function testUnitEnumAndStringInteroperability(): void
    {
        $gate = $this->getBasicGate();

        // Define with enum
        $gate->define(GateEnumTestAbilitiesUnitEnum::ManageUsers, fn ($user) => true);

        // Check with string (the enum name)
        $this->assertTrue($gate->allows('ManageUsers'));

        // Define with string
        $gate->define('ViewReports', fn ($user) => true);

        // Check with enum
        $this->assertTrue($gate->allows(GateEnumTestAbilitiesUnitEnum::ViewReports));
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    protected function getBasicGate(bool $isGuest = false): Gate
    {
        $container = new Container();

        return new Gate(
            $container,
            fn () => $isGuest ? null : new AccessGateTestAuthenticatable()
        );
    }
}
