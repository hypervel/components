<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Context\Context;
use Hypervel\Session\Store;
use Hypervel\Tests\TestCase;
use Mockery as m;
use SessionHandlerInterface;

enum SessionKey: string
{
    case User = 'user';
    case Token = 'token';
    case Settings = 'settings';
    case Counter = 'counter';
    case Items = 'items';
}

enum IntBackedKey: int
{
    case First = 1;
    case Second = 2;
}

enum SessionUnitKey
{
    case User;
    case Token;
    case Settings;
}

/**
 * @internal
 * @coversNothing
 */
class SessionStoreBackedEnumTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy('__session.store.started');
        Context::destroy('__session.store.id');
        Context::destroy('__session.store.attributes');

        parent::tearDown();
    }

    // =========================================================================
    // get() tests
    // =========================================================================

    public function testGetWithStringBackedEnum(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');

        $this->assertSame('john', $session->get(SessionKey::User));
    }

    public function testGetWithIntBackedEnum(): void
    {
        $session = $this->getSession();
        $session->put('1', 'first-value');

        $this->assertSame('first-value', $session->get(IntBackedKey::First));
    }

    public function testGetWithEnumReturnsDefault(): void
    {
        $session = $this->getSession();

        $this->assertSame('default', $session->get(SessionKey::User, 'default'));
    }

    // =========================================================================
    // put() tests
    // =========================================================================

    public function testPutWithSingleEnum(): void
    {
        $session = $this->getSession();
        $session->put(SessionKey::User, 'jane');

        $this->assertSame('jane', $session->get('user'));
        $this->assertSame('jane', $session->get(SessionKey::User));
    }

    public function testPutWithArrayOfStringKeys(): void
    {
        $session = $this->getSession();
        $session->put([
            SessionKey::User->value => 'john',
            SessionKey::Token->value => 'abc123',
        ]);

        $this->assertSame('john', $session->get(SessionKey::User));
        $this->assertSame('abc123', $session->get(SessionKey::Token));
    }

    /**
     * Test that put() normalizes enum keys in arrays.
     * Note: PHP auto-converts BackedEnums to their values when used as array keys,
     * so by the time the array reaches put(), keys are already strings.
     * This test verifies the overall behavior works correctly.
     */
    public function testPutWithMixedArrayKeysUsingEnumValues(): void
    {
        $session = $this->getSession();
        $session->put([
            SessionKey::User->value => 'john',
            'legacy_key' => 'legacy_value',
            SessionKey::Token->value => 'token123',
        ]);

        $this->assertSame('john', $session->get('user'));
        $this->assertSame('john', $session->get(SessionKey::User));
        $this->assertSame('legacy_value', $session->get('legacy_key'));
        $this->assertSame('token123', $session->get('token'));
        $this->assertSame('token123', $session->get(SessionKey::Token));
    }

    public function testPutWithIntBackedEnumKeyValues(): void
    {
        $session = $this->getSession();
        $session->put([
            (string) IntBackedKey::First->value => 'first-value',
            (string) IntBackedKey::Second->value => 'second-value',
        ]);

        $this->assertSame('first-value', $session->get('1'));
        $this->assertSame('first-value', $session->get(IntBackedKey::First));
        $this->assertSame('second-value', $session->get('2'));
        $this->assertSame('second-value', $session->get(IntBackedKey::Second));
    }

    // =========================================================================
    // exists() tests
    // =========================================================================

    public function testExistsWithSingleEnum(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');

        $this->assertTrue($session->exists(SessionKey::User));
        $this->assertFalse($session->exists(SessionKey::Token));
    }

    public function testExistsWithArrayOfEnums(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');
        $session->put('token', 'abc');

        $this->assertTrue($session->exists([SessionKey::User, SessionKey::Token]));
        $this->assertFalse($session->exists([SessionKey::User, SessionKey::Settings]));
    }

    public function testExistsWithMixedArrayEnumsAndStrings(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');
        $session->put('legacy', 'value');

        $this->assertTrue($session->exists([SessionKey::User, 'legacy']));
        $this->assertFalse($session->exists([SessionKey::User, 'nonexistent']));
    }

    // =========================================================================
    // missing() tests
    // =========================================================================

    public function testMissingWithSingleEnum(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');

        $this->assertFalse($session->missing(SessionKey::User));
        $this->assertTrue($session->missing(SessionKey::Token));
    }

    public function testMissingWithArrayOfEnums(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');
        $session->put('token', 'abc');

        // All keys exist - missing returns false
        $this->assertFalse($session->missing([SessionKey::User, SessionKey::Token]));

        // Some keys missing - missing returns true
        $this->assertTrue($session->missing([SessionKey::Token, SessionKey::Settings]));
    }

    public function testMissingWithMixedArrayEnumsAndStrings(): void
    {
        $session = $this->getSession();

        $this->assertTrue($session->missing([SessionKey::User, 'legacy']));

        $session->put('user', 'john');
        $session->put('legacy', 'value');

        $this->assertFalse($session->missing([SessionKey::User, 'legacy']));
    }

    // =========================================================================
    // has() tests
    // =========================================================================

    public function testHasWithSingleEnum(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');
        $session->put('token', null);

        $this->assertTrue($session->has(SessionKey::User));
        $this->assertFalse($session->has(SessionKey::Token)); // null value
        $this->assertFalse($session->has(SessionKey::Settings)); // doesn't exist
    }

    public function testHasWithArrayOfEnums(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');
        $session->put('token', 'abc');

        $this->assertTrue($session->has([SessionKey::User, SessionKey::Token]));
        $this->assertFalse($session->has([SessionKey::User, SessionKey::Settings]));
    }

    public function testHasWithMixedArrayEnumsAndStrings(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');
        $session->put('legacy', 'value');

        $this->assertTrue($session->has([SessionKey::User, 'legacy']));
        $this->assertFalse($session->has([SessionKey::User, 'nonexistent']));
    }

    // =========================================================================
    // hasAny() tests
    // =========================================================================

    public function testHasAnyWithSingleEnum(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');

        $this->assertTrue($session->hasAny(SessionKey::User));
        $this->assertFalse($session->hasAny(SessionKey::Token));
    }

    public function testHasAnyWithArrayOfEnums(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');

        $this->assertTrue($session->hasAny([SessionKey::User, SessionKey::Token]));
        $this->assertFalse($session->hasAny([SessionKey::Token, SessionKey::Settings]));
    }

    public function testHasAnyWithMixedArrayEnumsAndStrings(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');

        $this->assertTrue($session->hasAny([SessionKey::Token, 'user']));
        $this->assertTrue($session->hasAny(['nonexistent', SessionKey::User]));
        $this->assertFalse($session->hasAny([SessionKey::Token, 'nonexistent']));
    }

    // =========================================================================
    // pull() tests
    // =========================================================================

    public function testPullWithEnum(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');

        $this->assertSame('john', $session->pull(SessionKey::User));
        $this->assertFalse($session->has('user'));
    }

    public function testPullWithEnumReturnsDefault(): void
    {
        $session = $this->getSession();

        $this->assertSame('default', $session->pull(SessionKey::User, 'default'));
    }

    // =========================================================================
    // forget() tests
    // =========================================================================

    public function testForgetWithSingleEnum(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');
        $session->put('token', 'abc');

        $session->forget(SessionKey::User);

        $this->assertFalse($session->has('user'));
        $this->assertTrue($session->has('token'));
    }

    public function testForgetWithArrayOfEnums(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');
        $session->put('token', 'abc');
        $session->put('settings', ['dark' => true]);

        $session->forget([SessionKey::User, SessionKey::Token]);

        $this->assertFalse($session->has('user'));
        $this->assertFalse($session->has('token'));
        $this->assertTrue($session->has('settings'));
    }

    public function testForgetWithMixedArrayEnumsAndStrings(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');
        $session->put('legacy', 'value');
        $session->put('token', 'abc');

        $session->forget([SessionKey::User, 'legacy']);

        $this->assertFalse($session->has('user'));
        $this->assertFalse($session->has('legacy'));
        $this->assertTrue($session->has('token'));
    }

    // =========================================================================
    // only() tests
    // =========================================================================

    public function testOnlyWithArrayOfEnums(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');
        $session->put('token', 'abc');
        $session->put('settings', ['dark' => true]);

        $result = $session->only([SessionKey::User, SessionKey::Token]);

        $this->assertSame(['user' => 'john', 'token' => 'abc'], $result);
    }

    public function testOnlyWithMixedArrayEnumsAndStrings(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');
        $session->put('legacy', 'value');
        $session->put('token', 'abc');

        $result = $session->only([SessionKey::User, 'legacy']);

        $this->assertSame(['user' => 'john', 'legacy' => 'value'], $result);
    }

    public function testOnlyWithIntBackedEnums(): void
    {
        $session = $this->getSession();
        $session->put('1', 'first');
        $session->put('2', 'second');
        $session->put('3', 'third');

        $result = $session->only([IntBackedKey::First, IntBackedKey::Second]);

        $this->assertSame(['1' => 'first', '2' => 'second'], $result);
    }

    // =========================================================================
    // except() tests
    // =========================================================================

    public function testExceptWithArrayOfEnums(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');
        $session->put('token', 'abc');
        $session->put('settings', ['dark' => true]);

        $result = $session->except([SessionKey::User, SessionKey::Token]);

        $this->assertSame(['settings' => ['dark' => true]], $result);
    }

    public function testExceptWithMixedArrayEnumsAndStrings(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');
        $session->put('legacy', 'value');
        $session->put('token', 'abc');

        $result = $session->except([SessionKey::User, 'legacy']);

        $this->assertSame(['token' => 'abc'], $result);
    }

    // =========================================================================
    // remove() tests
    // =========================================================================

    public function testRemoveWithEnum(): void
    {
        $session = $this->getSession();
        $session->put('user', 'john');

        $value = $session->remove(SessionKey::User);

        $this->assertSame('john', $value);
        $this->assertFalse($session->has('user'));
    }

    // =========================================================================
    // remember() tests
    // =========================================================================

    public function testRememberWithEnum(): void
    {
        $session = $this->getSession();

        $result = $session->remember(SessionKey::User, fn () => 'computed');

        $this->assertSame('computed', $result);
        $this->assertSame('computed', $session->get(SessionKey::User));

        // Second call should return cached value
        $result2 = $session->remember(SessionKey::User, fn () => 'different');
        $this->assertSame('computed', $result2);
    }

    // =========================================================================
    // push() tests
    // =========================================================================

    public function testPushWithEnum(): void
    {
        $session = $this->getSession();

        $session->push(SessionKey::Items, 'item1');
        $session->push(SessionKey::Items, 'item2');

        $this->assertSame(['item1', 'item2'], $session->get(SessionKey::Items));
    }

    // =========================================================================
    // increment() / decrement() tests
    // =========================================================================

    public function testIncrementWithEnum(): void
    {
        $session = $this->getSession();

        $session->increment(SessionKey::Counter);
        $this->assertSame(1, $session->get(SessionKey::Counter));

        $session->increment(SessionKey::Counter, 5);
        $this->assertSame(6, $session->get(SessionKey::Counter));
    }

    public function testDecrementWithEnum(): void
    {
        $session = $this->getSession();
        $session->put(SessionKey::Counter, 10);

        $session->decrement(SessionKey::Counter);
        $this->assertSame(9, $session->get(SessionKey::Counter));

        $session->decrement(SessionKey::Counter, 4);
        $this->assertSame(5, $session->get(SessionKey::Counter));
    }

    // =========================================================================
    // flash() tests
    // =========================================================================

    public function testFlashWithEnum(): void
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([]));
        $session->start();

        $session->flash(SessionKey::User, 'flash-value');

        $this->assertTrue($session->has(SessionKey::User));
        $this->assertSame('flash-value', $session->get(SessionKey::User));

        // Verify key is stored as string in _flash.new
        $flashNew = $session->get('_flash.new');
        $this->assertContains('user', $flashNew);
    }

    public function testFlashWithEnumIsProperlyAged(): void
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([]));
        $session->start();

        $session->flash(SessionKey::User, 'flash-value');
        $session->ageFlashData();

        // After aging, key should be in _flash.old
        $this->assertContains('user', $session->get('_flash.old', []));
        $this->assertNotContains('user', $session->get('_flash.new', []));

        // Value should still exist
        $this->assertTrue($session->has(SessionKey::User));

        // Age again - should be removed
        $session->ageFlashData();
        $this->assertFalse($session->has(SessionKey::User));
    }

    // =========================================================================
    // now() tests
    // =========================================================================

    public function testNowWithEnum(): void
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([]));
        $session->start();

        $session->now(SessionKey::User, 'now-value');

        $this->assertTrue($session->has(SessionKey::User));
        $this->assertSame('now-value', $session->get(SessionKey::User));

        // Verify key is stored as string in _flash.old (immediate expiry)
        $flashOld = $session->get('_flash.old');
        $this->assertContains('user', $flashOld);
    }

    // =========================================================================
    // hasOldInput() / getOldInput() tests
    // =========================================================================

    public function testHasOldInputWithEnum(): void
    {
        $session = $this->getSession();
        $session->put('_old_input', ['user' => 'john', 'email' => 'john@example.com']);

        $this->assertTrue($session->hasOldInput(SessionKey::User));
        $this->assertFalse($session->hasOldInput(SessionKey::Token));
    }

    public function testGetOldInputWithEnum(): void
    {
        $session = $this->getSession();
        $session->put('_old_input', ['user' => 'john', 'email' => 'john@example.com']);

        $this->assertSame('john', $session->getOldInput(SessionKey::User));
        $this->assertNull($session->getOldInput(SessionKey::Token));
        $this->assertSame('default', $session->getOldInput(SessionKey::Token, 'default'));
    }

    // =========================================================================
    // Interoperability tests - enum and string access same data
    // =========================================================================

    public function testEnumAndStringAccessSameData(): void
    {
        $session = $this->getSession();

        // Set with enum, get with string
        $session->put(SessionKey::User, 'value1');
        $this->assertSame('value1', $session->get('user'));

        // Set with string, get with enum
        $session->put('token', 'value2');
        $this->assertSame('value2', $session->get(SessionKey::Token));

        // Verify both work together
        $this->assertTrue($session->has('user'));
        $this->assertTrue($session->has(SessionKey::User));
        $this->assertTrue($session->exists(['user', SessionKey::Token]));
    }

    public function testIntBackedEnumInteroperability(): void
    {
        $session = $this->getSession();

        $session->put(IntBackedKey::First, 'enum-value');
        $this->assertSame('enum-value', $session->get('1'));

        $session->put('2', 'string-value');
        $this->assertSame('string-value', $session->get(IntBackedKey::Second));
    }

    // =========================================================================
    // UnitEnum tests - uses enum name as key
    // =========================================================================

    public function testGetWithUnitEnum(): void
    {
        $session = $this->getSession();
        $session->put('User', 'john');

        $this->assertSame('john', $session->get(SessionUnitKey::User));
    }

    public function testPutWithUnitEnum(): void
    {
        $session = $this->getSession();
        $session->put(SessionUnitKey::User, 'jane');

        // UnitEnum uses ->name, so key is 'User' not 'user'
        $this->assertSame('jane', $session->get('User'));
        $this->assertSame('jane', $session->get(SessionUnitKey::User));
    }

    public function testExistsWithUnitEnum(): void
    {
        $session = $this->getSession();
        $session->put('User', 'john');

        $this->assertTrue($session->exists(SessionUnitKey::User));
        $this->assertFalse($session->exists(SessionUnitKey::Token));
    }

    public function testHasWithUnitEnum(): void
    {
        $session = $this->getSession();
        $session->put(SessionUnitKey::User, 'john');
        $session->put(SessionUnitKey::Token, null);

        $this->assertTrue($session->has(SessionUnitKey::User));
        $this->assertFalse($session->has(SessionUnitKey::Token)); // null value
        $this->assertFalse($session->has(SessionUnitKey::Settings)); // doesn't exist
    }

    public function testHasAnyWithUnitEnum(): void
    {
        $session = $this->getSession();
        $session->put(SessionUnitKey::User, 'john');

        $this->assertTrue($session->hasAny([SessionUnitKey::User, SessionUnitKey::Token]));
        $this->assertFalse($session->hasAny([SessionUnitKey::Token, SessionUnitKey::Settings]));
    }

    public function testPullWithUnitEnum(): void
    {
        $session = $this->getSession();
        $session->put('User', 'john');

        $this->assertSame('john', $session->pull(SessionUnitKey::User));
        $this->assertFalse($session->has('User'));
    }

    public function testForgetWithUnitEnum(): void
    {
        $session = $this->getSession();
        $session->put(SessionUnitKey::User, 'john');
        $session->put(SessionUnitKey::Token, 'abc');

        $session->forget(SessionUnitKey::User);

        $this->assertFalse($session->has('User'));
        $this->assertTrue($session->has('Token'));
    }

    public function testForgetWithArrayOfUnitEnums(): void
    {
        $session = $this->getSession();
        $session->put(SessionUnitKey::User, 'john');
        $session->put(SessionUnitKey::Token, 'abc');
        $session->put(SessionUnitKey::Settings, ['dark' => true]);

        $session->forget([SessionUnitKey::User, SessionUnitKey::Token]);

        $this->assertFalse($session->has('User'));
        $this->assertFalse($session->has('Token'));
        $this->assertTrue($session->has('Settings'));
    }

    public function testOnlyWithUnitEnums(): void
    {
        $session = $this->getSession();
        $session->put('User', 'john');
        $session->put('Token', 'abc');
        $session->put('Settings', ['dark' => true]);

        $result = $session->only([SessionUnitKey::User, SessionUnitKey::Token]);

        $this->assertSame(['User' => 'john', 'Token' => 'abc'], $result);
    }

    public function testExceptWithUnitEnums(): void
    {
        $session = $this->getSession();
        $session->put('User', 'john');
        $session->put('Token', 'abc');
        $session->put('Settings', ['dark' => true]);

        $result = $session->except([SessionUnitKey::User, SessionUnitKey::Token]);

        $this->assertSame(['Settings' => ['dark' => true]], $result);
    }

    public function testRemoveWithUnitEnum(): void
    {
        $session = $this->getSession();
        $session->put('User', 'john');

        $value = $session->remove(SessionUnitKey::User);

        $this->assertSame('john', $value);
        $this->assertFalse($session->has('User'));
    }

    public function testRememberWithUnitEnum(): void
    {
        $session = $this->getSession();

        $result = $session->remember(SessionUnitKey::User, fn () => 'computed');

        $this->assertSame('computed', $result);
        $this->assertSame('computed', $session->get('User'));
    }

    public function testPushWithUnitEnum(): void
    {
        $session = $this->getSession();

        $session->push(SessionUnitKey::User, 'item1');
        $session->push(SessionUnitKey::User, 'item2');

        $this->assertSame(['item1', 'item2'], $session->get('User'));
    }

    public function testIncrementWithUnitEnum(): void
    {
        $session = $this->getSession();

        $session->increment(SessionUnitKey::User);
        $this->assertSame(1, $session->get('User'));

        $session->increment(SessionUnitKey::User, 5);
        $this->assertSame(6, $session->get('User'));
    }

    public function testDecrementWithUnitEnum(): void
    {
        $session = $this->getSession();
        $session->put('User', 10);

        $session->decrement(SessionUnitKey::User);
        $this->assertSame(9, $session->get('User'));
    }

    public function testFlashWithUnitEnum(): void
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([]));
        $session->start();

        $session->flash(SessionUnitKey::User, 'flash-value');

        $this->assertTrue($session->has('User'));
        $this->assertSame('flash-value', $session->get('User'));

        // Verify key is stored as string in _flash.new
        $flashNew = $session->get('_flash.new');
        $this->assertContains('User', $flashNew);
    }

    public function testNowWithUnitEnum(): void
    {
        $session = $this->getSession();
        $session->getHandler()->shouldReceive('read')->once()->andReturn(serialize([]));
        $session->start();

        $session->now(SessionUnitKey::User, 'now-value');

        $this->assertTrue($session->has('User'));
        $this->assertSame('now-value', $session->get('User'));

        // Verify key is stored as string in _flash.old
        $flashOld = $session->get('_flash.old');
        $this->assertContains('User', $flashOld);
    }

    public function testHasOldInputWithUnitEnum(): void
    {
        $session = $this->getSession();
        $session->put('_old_input', ['User' => 'john', 'email' => 'john@example.com']);

        $this->assertTrue($session->hasOldInput(SessionUnitKey::User));
        $this->assertFalse($session->hasOldInput(SessionUnitKey::Token));
    }

    public function testGetOldInputWithUnitEnum(): void
    {
        $session = $this->getSession();
        $session->put('_old_input', ['User' => 'john', 'email' => 'john@example.com']);

        $this->assertSame('john', $session->getOldInput(SessionUnitKey::User));
        $this->assertNull($session->getOldInput(SessionUnitKey::Token));
        $this->assertSame('default', $session->getOldInput(SessionUnitKey::Token, 'default'));
    }

    public function testUnitEnumInteroperability(): void
    {
        $session = $this->getSession();

        // Set with UnitEnum, get with string
        $session->put(SessionUnitKey::User, 'value1');
        $this->assertSame('value1', $session->get('User'));

        // Set with string, get with UnitEnum
        $session->put('Token', 'value2');
        $this->assertSame('value2', $session->get(SessionUnitKey::Token));
    }

    public function testMixedBackedAndUnitEnums(): void
    {
        $session = $this->getSession();

        // BackedEnum uses ->value ('user'), UnitEnum uses ->name ('User')
        $session->put(SessionKey::User, 'backed-value');
        $session->put(SessionUnitKey::User, 'unit-value');

        // These are different keys
        $this->assertSame('backed-value', $session->get('user'));
        $this->assertSame('unit-value', $session->get('User'));
        $this->assertSame('backed-value', $session->get(SessionKey::User));
        $this->assertSame('unit-value', $session->get(SessionUnitKey::User));
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    protected function getSession(string $serialization = 'php'): Store
    {
        $store = new Store(
            'test-session',
            m::mock(SessionHandlerInterface::class),
            $serialization
        );

        $store->setId('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        return $store;
    }
}
