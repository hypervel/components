<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Access;

use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Gate;
use Hypervel\Auth\Access\Response;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Tests\Auth\Stub\AccessGateTestAuthenticatable;
use Hypervel\Tests\Auth\Stub\AccessGateTestBeforeCallback;
use Hypervel\Tests\Auth\Stub\AccessGateTestClass;
use Hypervel\Tests\Auth\Stub\AccessGateTestClassForGuest;
use Hypervel\Tests\Auth\Stub\AccessGateTestCustomResource;
use Hypervel\Tests\Auth\Stub\AccessGateTestDummy;
use Hypervel\Tests\Auth\Stub\AccessGateTestDummyInterface;
use Hypervel\Tests\Auth\Stub\AccessGateTestGuestInvokableClass;
use Hypervel\Tests\Auth\Stub\AccessGateTestGuestNullableInvokable;
use Hypervel\Tests\Auth\Stub\AccessGateTestInvokableClass;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicy;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicyThatAllowsGuests;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicyThrowingAuthorizationException;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicyWithAllPermissions;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicyWithBefore;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicyWithCode;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicyWithDeniedResponseObject;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicyWithMixedPermissions;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicyWithNonGuestBefore;
use Hypervel\Tests\Auth\Stub\AccessGateTestPolicyWithNoPermissions;
use Hypervel\Tests\Auth\Stub\AccessGateTestResource;
use Hypervel\Tests\Auth\Stub\AccessGateTestStaticClass;
use Hypervel\Tests\Auth\Stub\AccessGateTestSubDummy;
use Hypervel\Tests\Auth\Stub\DummyWithoutUsePolicy;
use Hypervel\Tests\Auth\Stub\DummyWithUsePolicy;
use Hypervel\Tests\Auth\Stub\DummyWithUsePolicyPolicy;
use Hypervel\Tests\Auth\Stub\SubDummyWithUsePolicy;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 * @coversNothing
 */
class GateTest extends TestCase
{
    public function testBasicClosuresCanBeDefined()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', fn ($user) => true);
        $gate->define('bar', fn ($user) => false);

        $this->assertTrue($gate->check('foo'));
        $this->assertFalse($gate->check('bar'));
    }

    public function testBeforeCanTakeAnArrayCallbackAsObject()
    {
        $gate = $this->getGuestGate();

        $gate->before([new AccessGateTestBeforeCallback(), 'allowEverything']);

        $this->assertTrue($gate->check('anything'));
    }

    public function testBeforeCanTakeAnArrayCallbackAsObjectStatic()
    {
        $gate = $this->getGuestGate();

        $gate->before([new AccessGateTestBeforeCallback(), 'allowEverythingStatically']);

        $this->assertTrue($gate->check('anything'));
    }

    public function testBeforeCanTakeAnArrayCallbackWithStaticMethod()
    {
        $gate = $this->getGuestGate();

        $gate->before([AccessGateTestBeforeCallback::class, 'allowEverythingStatically']);

        $this->assertTrue($gate->check('anything'));
    }

    public function testBeforeCanAllowGuests()
    {
        $gate = $this->getGuestGate();

        $gate->before(fn (?Authenticatable $user) => true);

        $this->assertTrue($gate->check('anything'));
    }

    public function testAfterCanAllowGuests()
    {
        $gate = $this->getGuestGate();

        $gate->after(fn (?Authenticatable $user) => true);

        $this->assertTrue($gate->check('anything'));
    }

    public function testClosuresCanAllowGuestUsers()
    {
        $gate = $this->getGuestGate();

        $gate->define('foo', fn (?Authenticatable $user) => true);
        $gate->define('bar', fn (?Authenticatable $user) => false);

        $this->assertTrue($gate->check('foo'));
        $this->assertFalse($gate->check('bar'));
    }

    public function testPoliciesCanAllowGuests()
    {
        unset($_SERVER['__hyperf.testBefore']);

        $gate = $this->getGuestGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyThatAllowsGuests::class);

        $this->assertTrue($gate->check('edit', new AccessGateTestDummy()));
        $this->assertFalse($gate->check('update', new AccessGateTestDummy()));
        $this->assertTrue($_SERVER['__hyperf.testBefore']);

        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyThatAllowsGuests::class);

        $this->assertTrue($gate->check('edit', new AccessGateTestDummy()));
        $this->assertTrue($gate->check('update', new AccessGateTestDummy()));

        unset($_SERVER['__hyperf.testBefore']);
    }

    public function testPolicyBeforeNotCalledWithGuestsIfItDoesntAllowThem()
    {
        $_SERVER['__hyperf.testBefore'] = false;

        $gate = $this->getGuestGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithNonGuestBefore::class);

        $this->assertTrue($gate->check('edit', new AccessGateTestDummy()));
        $this->assertFalse($gate->check('update', new AccessGateTestDummy()));
        $this->assertFalse($_SERVER['__hyperf.testBefore']);

        unset($_SERVER['__hyperf.testBefore']);
    }

    public function testBeforeAndAfterCallbacksCanAllowGuests()
    {
        $_SERVER['__hyperf.gateBefore'] = false;
        $_SERVER['__hyperf.gateBefore2'] = false;
        $_SERVER['__hyperf.gateAfter'] = false;
        $_SERVER['__hyperf.gateAfter2'] = false;

        $gate = $this->getGuestGate();

        $gate->before(function (?Authenticatable $user) {
            $_SERVER['__hyperf.gateBefore'] = true;
        });

        $gate->after(function (?Authenticatable $user) {
            $_SERVER['__hyperf.gateAfter'] = true;
        });

        $gate->before(function (Authenticatable $user) {
            $_SERVER['__hyperf.gateBefore2'] = true;
        });

        $gate->after(function (Authenticatable $user) {
            $_SERVER['__hyperf.gateAfter2'] = true;
        });

        $gate->define('foo', function ($user = null) {
            return true;
        });

        $this->assertTrue($gate->check('foo'));

        $this->assertTrue($_SERVER['__hyperf.gateBefore']);
        $this->assertFalse($_SERVER['__hyperf.gateBefore2']);
        $this->assertTrue($_SERVER['__hyperf.gateAfter']);
        $this->assertFalse($_SERVER['__hyperf.gateAfter2']);

        unset(
            $_SERVER['__hyperf.gateBefore'],
            $_SERVER['__hyperf.gateBefore2'],
            $_SERVER['__hyperf.gateAfter'],
            $_SERVER['__hyperf.gateAfter2']
        );
    }

    public function testResourceGatesCanBeDefined()
    {
        $gate = $this->getBasicGate();

        $gate->resource('test', AccessGateTestResource::class);

        $dummy = new AccessGateTestDummy();

        $this->assertTrue($gate->check('test.view'));
        $this->assertTrue($gate->check('test.create'));
        $this->assertTrue($gate->check('test.update', $dummy));
        $this->assertTrue($gate->check('test.delete', $dummy));
    }

    public function testCustomResourceGatesCanBeDefined()
    {
        $gate = $this->getBasicGate();

        $abilities = [
            'ability1' => 'foo',
            'ability2' => 'bar',
        ];

        $gate->resource('test', AccessGateTestCustomResource::class, $abilities);

        $this->assertTrue($gate->check('test.ability1'));
        $this->assertTrue($gate->check('test.ability2'));
    }

    public function testBeforeCallbacksCanOverrideResultIfNecessary()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', function ($user) {
            return true;
        });
        $gate->before(function ($user, $ability) {
            $this->assertSame('foo', $ability);

            return false;
        });

        $this->assertFalse($gate->check('foo'));
    }

    public function testBeforeCallbacksDontInterruptGateCheckIfNoValueIsReturned()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', fn ($user) => true);
        $gate->before(function () {});

        $this->assertTrue($gate->check('foo'));
    }

    public function testAfterCallbacksAreCalledWithResult()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', fn ($user) => true);
        $gate->define('bar', fn ($user) => false);

        $gate->after(function ($user, $ability, $result) {
            if ($ability === 'foo') {
                return $this->assertTrue($result, 'After callback on `foo` should receive true as result');
            }

            if ($ability === 'bar') {
                return $this->assertFalse($result, 'After callback on `bar` should receive false as result');
            }

            return $this->assertNull($result, 'After callback on `missing` should receive null as result');
        });

        $this->assertTrue($gate->check('foo'));
        $this->assertFalse($gate->check('bar'));
        $this->assertFalse($gate->check('missing'));
    }

    public function testAfterCallbacksCanAllowIfNull()
    {
        $gate = $this->getBasicGate();

        $gate->after(fn ($user, $ability, $result) => true);

        $this->assertTrue($gate->allows('null'));
    }

    public function testAfterCallbacksDoNotOverridePreviousResult()
    {
        $gate = $this->getBasicGate();

        $gate->define('deny', fn ($user) => false);
        $gate->define('allow', fn ($user) => true);
        $gate->after(fn ($user, $ability, $result) => ! $result);

        $this->assertTrue($gate->allows('allow'));
        $this->assertTrue($gate->denies('deny'));
    }

    public function testAfterCallbacksDoNotOverrideEachOther()
    {
        $gate = $this->getBasicGate();

        $gate->after(fn ($user, $ability, $result) => $ability === 'allow');
        $gate->after(fn ($user, $ability, $result) => ! $result);

        $this->assertTrue($gate->allows('allow'));
        $this->assertTrue($gate->denies('deny'));
    }

    public function testCurrentUserThatIsOnGateAlwaysInjectedIntoClosureCallbacks()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', function ($user) {
            $this->assertSame(1, $user->getAuthIdentifier());

            return true;
        });

        $this->assertTrue($gate->check('foo'));
    }

    public function testASingleArgumentCanBePassedWhenCheckingAbilities()
    {
        $gate = $this->getBasicGate();

        $dummy = new AccessGateTestDummy();

        $gate->before(function ($user, $ability, array $arguments) use ($dummy) {
            $this->assertCount(1, $arguments);
            $this->assertSame($dummy, $arguments[0]);
        });

        $gate->define('foo', function ($user, $x) use ($dummy) {
            $this->assertSame($dummy, $x);

            return true;
        });

        $gate->after(function ($user, $ability, $result, array $arguments) use ($dummy) {
            $this->assertCount(1, $arguments);
            $this->assertSame($dummy, $arguments[0]);
        });

        $this->assertTrue($gate->check('foo', $dummy));
    }

    public function testMultipleArgumentsCanBePassedWhenCheckingAbilities()
    {
        $gate = $this->getBasicGate();

        $dummy1 = new AccessGateTestDummy();
        $dummy2 = new AccessGateTestDummy();

        $gate->before(function ($user, $ability, array $arguments) use ($dummy1, $dummy2) {
            $this->assertCount(2, $arguments);
            $this->assertSame([$dummy1, $dummy2], $arguments);
        });

        $gate->define('foo', function ($user, $x, $y) use ($dummy1, $dummy2) {
            $this->assertSame($dummy1, $x);
            $this->assertSame($dummy2, $y);

            return true;
        });

        $gate->after(function ($user, $ability, $result, array $arguments) use ($dummy1, $dummy2) {
            $this->assertCount(2, $arguments);
            $this->assertSame([$dummy1, $dummy2], $arguments);
        });

        $this->assertTrue($gate->check('foo', [$dummy1, $dummy2]));
    }

    public function testClassesCanBeDefinedAsCallbacksUsingAtNotation()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', AccessGateTestClass::class . '@foo');

        $this->assertTrue($gate->check('foo'));
    }

    public function testInvokableClassesCanBeDefined()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', AccessGateTestInvokableClass::class);

        $this->assertTrue($gate->check('foo'));
    }

    public function testGatesCanBeDefinedUsingAnArrayCallback()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', [new AccessGateTestStaticClass(), 'foo']);

        $this->assertTrue($gate->check('foo'));
    }

    public function testGatesCanBeDefinedUsingAnArrayCallbackWithStaticMethod()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', [AccessGateTestStaticClass::class, 'foo']);

        $this->assertTrue($gate->check('foo'));
    }

    public function testPolicyClassesCanBeDefinedToHandleChecksForGivenType()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('update', new AccessGateTestDummy()));
    }

    public function testPolicyClassesHandleChecksForAllSubtypes()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('update', new AccessGateTestSubDummy()));
    }

    public function testPolicyClassesHandleChecksForInterfaces()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummyInterface::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('update', new AccessGateTestSubDummy()));
    }

    public function testPolicyConvertsDashToCamel()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('update-dash', new AccessGateTestDummy()));
    }

    public function testPolicyDefaultToFalseIfMethodDoesNotExistAndGateDoesNotExist()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertFalse($gate->check('nonexistent_method', new AccessGateTestDummy()));
    }

    public function testPolicyClassesCanBeDefinedToHandleChecksForGivenClassName()
    {
        $gate = $this->getBasicGate(true);

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('create', [AccessGateTestDummy::class, true]));
    }

    public function testPoliciesMayHaveBeforeMethodsToOverrideChecks()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithBefore::class);

        $this->assertTrue($gate->check('update', new AccessGateTestDummy()));
    }

    public function testPoliciesAlwaysOverrideClosuresWithSameName()
    {
        $gate = $this->getBasicGate();

        $gate->define('update', fn () => $this->fail());

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('update', new AccessGateTestDummy()));
    }

    public function testPoliciesDeferToGatesIfMethodDoesNotExist()
    {
        $gate = $this->getBasicGate();

        $gate->define('nonexistent_method', fn ($user) => true);

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('nonexistent_method', new AccessGateTestDummy()));
    }

    public function testForUserMethodAttachesANewUserToANewGateInstance()
    {
        $gate = $this->getBasicGate();

        // Assert that the callback receives the new user with ID of 2 instead of ID of 1...
        $gate->define('foo', function ($user) {
            $this->assertSame(2, $user->getAuthIdentifier());

            return true;
        });

        $this->assertTrue($gate->forUser(new class implements Authenticatable {
            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return 2;
            }

            public function getAuthPassword(): string
            {
                return 'dummy';
            }
        })->check('foo'));
    }

    public function testDefineSecondParameterShouldBeCallable()
    {
        $this->expectException(InvalidArgumentException::class);

        $gate = $this->getBasicGate();

        $gate->define('foo', []);
    }

    public function testAuthorizeThrowsUnauthorizedException()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('You are not an admin.');
        $this->expectExceptionCode(0);

        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $gate->authorize('create', new AccessGateTestDummy());
    }

    public function testAuthorizeThrowsUnauthorizedExceptionWithCustomStatusCode()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Not allowed to view as it is not published.');
        $this->expectExceptionCode('unpublished');

        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithCode::class);

        $gate->authorize('view', new AccessGateTestDummy());
    }

    public function testAuthorizeWithPolicyThatReturnsDeniedResponseObjectThrowsException()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Not allowed.');
        $this->expectExceptionCode('some_code');

        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithDeniedResponseObject::class);

        $gate->authorize('create', new AccessGateTestDummy());
    }

    public function testPolicyThatThrowsAuthorizationExceptionIsCaughtInInspect()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyThrowingAuthorizationException::class);

        $response = $gate->inspect('create', new AccessGateTestDummy());

        $this->assertTrue($response->denied());
        $this->assertFalse($response->allowed());
        $this->assertSame('Not allowed.', $response->message());
        $this->assertSame('some_code', $response->code());
    }

    public function testAuthorizeReturnsAllowedResponse()
    {
        $gate = $this->getBasicGate(true);

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $check = $gate->check('create', new AccessGateTestDummy());
        $response = $gate->authorize('create', new AccessGateTestDummy());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertNull($response->message());
        $this->assertTrue($check);
    }

    public function testResponseReturnsResponseWhenAbilityGranted()
    {
        $gate = $this->getBasicGate(true);

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithCode::class);

        $response = $gate->inspect('view', new AccessGateTestDummy());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertNull($response->message());
        $this->assertTrue($response->allowed());
        $this->assertFalse($response->denied());
        $this->assertNull($response->code());
    }

    public function testResponseReturnsResponseWhenAbilityDenied()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithCode::class);

        $response = $gate->inspect('view', new AccessGateTestDummy());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('Not allowed to view as it is not published.', $response->message());
        $this->assertFalse($response->allowed());
        $this->assertTrue($response->denied());
        $this->assertSame('unpublished', $response->code());
    }

    public function testAuthorizeReturnsAnAllowedResponseForATruthyReturn()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $response = $gate->authorize('update', new AccessGateTestDummy());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertNull($response->message());
    }

    public function testAllowIfAuthorizesTrue()
    {
        $response = $this->getBasicGate()->allowIf(true);

        $this->assertTrue($response->allowed());
    }

    public function testAllowIfAuthorizesIfGuest()
    {
        $response = $this->getBasicGate()->forUser(null)->allowIf(true);

        $this->assertTrue($response->allowed());
    }

    public function testAllowIfAuthorizesCallbackTrue()
    {
        $response = $this->getBasicGate()->allowIf(function ($user) {
            $this->assertSame(1, $user->getAuthIdentifier());

            return true;
        }, 'foo', 'bar');

        $this->assertTrue($response->allowed());
        $this->assertSame('foo', $response->message());
        $this->assertSame('bar', $response->code());
    }

    public function testAllowIfAuthorizesResponseAllowed()
    {
        $response = $this->getBasicGate()->allowIf(Response::allow('foo', 'bar'));

        $this->assertTrue($response->allowed());
        $this->assertSame('foo', $response->message());
        $this->assertSame('bar', $response->code());
    }

    public function testAllowIfAuthorizesCallbackResponseAllowed()
    {
        $response = $this->getBasicGate()->allowIf(function () {
            return Response::allow('quz', 'qux');
        }, 'foo', 'bar');

        $this->assertTrue($response->allowed());
        $this->assertSame('quz', $response->message());
        $this->assertSame('qux', $response->code());
    }

    public function testAllowsIfCallbackAcceptsGuestsWhenAuthenticated()
    {
        $response = $this->getBasicGate()->allowIf(function (?Authenticatable $user = null) {
            return $user !== null;
        });

        $this->assertTrue($response->allowed());
    }

    public function testAllowIfCallbackAcceptsGuestsWhenUnauthenticated()
    {
        $gate = $this->getBasicGate()->forUser(null);

        $response = $gate->allowIf(function (?Authenticatable $user = null) {
            return $user === null;
        });

        $this->assertTrue($response->allowed());
    }

    public function testAllowIfThrowsExceptionWhenFalse()
    {
        $this->expectException(AuthorizationException::class);

        $this->getBasicGate()->allowIf(false);
    }

    public function testAllowIfThrowsExceptionWhenCallbackFalse()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('foo');
        $this->expectExceptionCode('bar');

        $this->getBasicGate()->allowIf(function () {
            return false;
        }, 'foo', 'bar');
    }

    public function testAllowIfThrowsExceptionWhenResponseDenied()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('foo');
        $this->expectExceptionCode('bar');

        $this->getBasicGate()->allowIf(Response::deny('foo', 'bar'));
    }

    public function testAllowIfThrowsExceptionWhenCallbackResponseDenied()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('quz');
        $this->expectExceptionCode('qux');

        $this->getBasicGate()->allowIf(function () {
            return Response::deny('quz', 'qux');
        }, 'foo', 'bar');
    }

    public function testAllowIfThrowsExceptionIfUnauthenticated()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('foo');
        $this->expectExceptionCode('bar');

        $gate = $this->getBasicGate()->forUser(null);

        $gate->allowIf(function () {
            return true;
        }, 'foo', 'bar');
    }

    public function testAllowIfThrowsExceptionIfAuthUserExpectedWhenGuest()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('foo');
        $this->expectExceptionCode('bar');

        $gate = $this->getBasicGate()->forUser(null);

        $gate->allowIf(fn (Authenticatable $user) => true, 'foo', 'bar');
    }

    public function testDenyIfAuthorizesFalse()
    {
        $response = $this->getBasicGate()->denyIf(false);

        $this->assertTrue($response->allowed());
    }

    public function testDenyIfAuthorizesIfGuest()
    {
        $response = $this->getBasicGate()->forUser(null)->denyIf(false);

        $this->assertTrue($response->allowed());
    }

    public function testDenyIfAuthorizesCallbackFalse()
    {
        $response = $this->getBasicGate()->denyIf(function ($user) {
            $this->assertSame(1, $user->getAuthIdentifier());

            return false;
        }, 'foo', 'bar');

        $this->assertTrue($response->allowed());
        $this->assertSame('foo', $response->message());
        $this->assertSame('bar', $response->code());
    }

    public function testDenyIfAuthorizesResponseAllowed()
    {
        $response = $this->getBasicGate()->denyIf(Response::allow('foo', 'bar'));

        $this->assertTrue($response->allowed());
        $this->assertSame('foo', $response->message());
        $this->assertSame('bar', $response->code());
    }

    public function testDenyIfAuthorizesCallbackResponseAllowed()
    {
        $response = $this->getBasicGate()->denyIf(fn () => Response::allow('quz', 'qux'), 'foo', 'bar');

        $this->assertTrue($response->allowed());
        $this->assertSame('quz', $response->message());
        $this->assertSame('qux', $response->code());
    }

    public function testDenyIfCallbackAcceptsGuestsWhenAuthenticated()
    {
        $response = $this->getBasicGate()->denyIf(fn (?Authenticatable $user = null) => $user === null);

        $this->assertTrue($response->allowed());
    }

    public function testDenyIfCallbackAcceptsGuestsWhenUnauthenticated()
    {
        $gate = $this->getBasicGate()->forUser(null);

        $response = $gate->denyIf(fn (?Authenticatable $user = null) => $user !== null);

        $this->assertTrue($response->allowed());
    }

    public function testDenyIfThrowsExceptionWhenTrue()
    {
        $this->expectException(AuthorizationException::class);

        $this->getBasicGate()->denyIf(true);
    }

    public function testDenyIfThrowsExceptionWhenCallbackTrue()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('foo');
        $this->expectExceptionCode('bar');

        $this->getBasicGate()->denyIf(fn () => true, 'foo', 'bar');
    }

    public function testDenyIfThrowsExceptionWhenResponseDenied()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('foo');
        $this->expectExceptionCode('bar');

        $this->getBasicGate()->denyIf(Response::deny('foo', 'bar'));
    }

    public function testDenyIfThrowsExceptionWhenCallbackResponseDenied()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('quz');
        $this->expectExceptionCode('qux');

        $this->getBasicGate()->denyIf(fn () => Response::deny('quz', 'qux'), 'foo', 'bar');
    }

    public function testDenyIfThrowsExceptionIfUnauthenticated()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('foo');
        $this->expectExceptionCode('bar');

        $gate = $this->getBasicGate()->forUser(null);

        $gate->denyIf(fn () => false, 'foo', 'bar');
    }

    public function testDenyIfThrowsExceptionIfAuthUserExpectedWhenGuest()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('foo');
        $this->expectExceptionCode('bar');

        $gate = $this->getBasicGate()->forUser(null);

        $gate->denyIf(fn (Authenticatable $user) => false, 'foo', 'bar');
    }

    public function testAnyAbilityCheckPassesIfAllPass()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithAllPermissions::class);

        $this->assertTrue($gate->any(['edit', 'update'], new AccessGateTestDummy()));
    }

    public function testAnyAbilityCheckPassesIfAtLeastOnePasses()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithMixedPermissions::class);

        $this->assertTrue($gate->any(['edit', 'update'], new AccessGateTestDummy()));
    }

    public function testAnyAbilityCheckFailsIfNonePass()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithNoPermissions::class);

        $this->assertFalse($gate->any(['edit', 'update'], new AccessGateTestDummy()));
    }

    public function testNoneAbilityCheckPassesIfAllFail()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithNoPermissions::class);

        $this->assertTrue($gate->none(['edit', 'update'], new AccessGateTestDummy()));
    }

    public function testEveryAbilityCheckPassesIfAllPass()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithAllPermissions::class);

        $this->assertTrue($gate->check(['edit', 'update'], new AccessGateTestDummy()));
    }

    public function testEveryAbilityCheckFailsIfAtLeastOneFails()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithMixedPermissions::class);

        $this->assertFalse($gate->check(['edit', 'update'], new AccessGateTestDummy()));
    }

    public function testEveryAbilityCheckFailsIfNonePass()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithNoPermissions::class);

        $this->assertFalse($gate->check(['edit', 'update'], new AccessGateTestDummy()));
    }

    #[DataProvider('hasAbilitiesTestDataProvider')]
    public function testHasAbilities(array $abilitiesToSet, array|string $abilitiesToCheck, bool $expectedHasValue)
    {
        $gate = $this->getBasicGate();

        $gate->resource('test', AccessGateTestResource::class, $abilitiesToSet);

        $this->assertEquals($expectedHasValue, $gate->has($abilitiesToCheck));
    }

    public static function hasAbilitiesTestDataProvider(): array
    {
        $abilities = ['foo' => 'foo', 'bar' => 'bar'];
        $noAbilities = [];

        return [
            [$abilities, ['test.foo', 'test.bar'], true],
            [$abilities, ['test.bar', 'test.foo'], true],
            [$abilities, ['test.bar', 'test.foo', 'test.baz'], false],
            [$abilities, ['test.bar'], true],
            [$abilities, ['baz'], false],
            [$abilities, [''], false],
            [$abilities, [], true],
            [$abilities, 'test.bar', true],
            [$abilities, 'test.foo', true],
            [$abilities, '', false],
            [$noAbilities, '', false],
            [$noAbilities, [], true],
        ];
    }

    public function testClassesCanBeDefinedAsCallbacksUsingAtNotationForGuests()
    {
        $gate = $this->getGuestGate();

        $gate->define('foo', AccessGateTestClassForGuest::class . '@foo');
        $gate->define('obj_foo', [new AccessGateTestClassForGuest(), 'foo']);
        $gate->define('static_foo', [AccessGateTestClassForGuest::class, 'staticFoo']);
        $gate->define('static_@foo', AccessGateTestClassForGuest::class . '@staticFoo');
        $gate->define('bar', AccessGateTestClassForGuest::class . '@bar');
        $gate->define('invokable', AccessGateTestGuestInvokableClass::class);
        $gate->define('nullable_invokable', AccessGateTestGuestNullableInvokable::class);
        $gate->define('absent_invokable', 'someAbsentClass');

        AccessGateTestClassForGuest::$calledMethod = '';

        $this->assertTrue($gate->check('foo'));
        $this->assertSame('foo was called', AccessGateTestClassForGuest::$calledMethod);

        $this->assertTrue($gate->check('static_foo'));
        $this->assertSame('static foo was invoked', AccessGateTestClassForGuest::$calledMethod);

        $this->assertTrue($gate->check('bar'));
        $this->assertSame('bar got invoked', AccessGateTestClassForGuest::$calledMethod);

        $this->assertTrue($gate->check('static_@foo'));
        $this->assertSame('static foo was invoked', AccessGateTestClassForGuest::$calledMethod);

        $this->assertTrue($gate->check('invokable'));
        $this->assertSame('__invoke was called', AccessGateTestGuestInvokableClass::$calledMethod);

        $this->assertTrue($gate->check('nullable_invokable'));
        $this->assertSame('Nullable __invoke was called', AccessGateTestGuestNullableInvokable::$calledMethod);

        $this->assertFalse($gate->check('absent_invokable'));
    }

    public function testPolicyCanBeResolvedFromUsePolicyAttribute(): void
    {
        $gate = $this->getBasicGate();

        $this->assertInstanceOf(
            DummyWithUsePolicyPolicy::class,
            $gate->getPolicyFor(DummyWithUsePolicy::class)
        );
    }

    public function testPolicyFromUsePolicyAttributeWorksWithObjectInstance(): void
    {
        $gate = $this->getBasicGate();

        $this->assertInstanceOf(
            DummyWithUsePolicyPolicy::class,
            $gate->getPolicyFor(new DummyWithUsePolicy())
        );
    }

    public function testExplicitPolicyTakesPrecedenceOverUsePolicyAttribute(): void
    {
        $gate = $this->getBasicGate();

        // Register an explicit policy that should take precedence
        $gate->policy(DummyWithUsePolicy::class, AccessGateTestPolicy::class);

        $this->assertInstanceOf(
            AccessGateTestPolicy::class,
            $gate->getPolicyFor(DummyWithUsePolicy::class)
        );
    }

    public function testUsePolicyAttributeTakesPrecedenceOverSubclassFallback(): void
    {
        $gate = $this->getBasicGate();

        // Register a policy for the parent class
        $gate->policy(DummyWithUsePolicy::class, AccessGateTestPolicy::class);

        // SubDummyWithUsePolicy extends DummyWithUsePolicy but has its own #[UsePolicy] attribute
        // The attribute should take precedence over the subclass fallback
        $this->assertInstanceOf(
            DummyWithUsePolicyPolicy::class,
            $gate->getPolicyFor(SubDummyWithUsePolicy::class)
        );
    }

    public function testGetPolicyForReturnsNullForClassWithoutUsePolicyAttribute(): void
    {
        $gate = $this->getBasicGate();

        $this->assertNull($gate->getPolicyFor(DummyWithoutUsePolicy::class));
    }

    public function testCanSetDenialResponseInConstructor()
    {
        $gate = $this->getGuestGate();

        $gate->defaultDenialResponse(Response::denyWithStatus(999, 'my_message', 'abc'));

        $gate->define('foo', fn () => false);

        $response = $gate->inspect('foo', new AccessGateTestDummy());

        $this->assertTrue($response->denied());
        $this->assertFalse($response->allowed());
        $this->assertSame('my_message', $response->message());
        $this->assertSame('abc', $response->code());
        $this->assertSame(999, $response->status());
    }

    public function testCanSetDenialResponse()
    {
        $gate = $this->getGuestGate();

        $gate->define('foo', fn () => false);
        $gate->defaultDenialResponse(Response::denyWithStatus(404, 'not_found', 'xyz'));

        $response = $gate->inspect('foo', new AccessGateTestDummy());
        $this->assertTrue($response->denied());
        $this->assertFalse($response->allowed());
        $this->assertSame('not_found', $response->message());
        $this->assertSame('xyz', $response->code());
        $this->assertSame(404, $response->status());
    }

    private function getBasicGate($isAdmin = false): Gate
    {
        return new Gate(
            $this->getContainer(),
            fn () => new AccessGateTestAuthenticatable($isAdmin)
        );
    }

    private function getGuestGate(): Gate
    {
        return new Gate(
            $this->getContainer(),
            function () {}
        );
    }

    private function getContainer(): Container
    {
        return new Container(new DefinitionSource([]));
    }
}
