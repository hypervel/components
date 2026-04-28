<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\CoroutineContext;
use Hypervel\Tests\TestCase;

enum ContextKeyBackedEnum: string
{
    case CurrentUser = 'current-user';
    case RequestId = 'request-id';
    case Tenant = 'tenant';
}

enum ContextKeyIntBackedEnum: int
{
    case UserId = 1;
    case SessionId = 2;
}

enum ContextKeyUnitEnum
{
    case Locale;
    case Theme;
}

class ContextEnumTest extends TestCase
{
    public function testSetAndGetWithBackedEnum()
    {
        CoroutineContext::set(ContextKeyBackedEnum::CurrentUser, 'user-123');

        $this->assertSame('user-123', CoroutineContext::get(ContextKeyBackedEnum::CurrentUser));
    }

    public function testSetAndGetWithUnitEnum()
    {
        CoroutineContext::set(ContextKeyUnitEnum::Locale, 'en-US');

        $this->assertSame('en-US', CoroutineContext::get(ContextKeyUnitEnum::Locale));
    }

    public function testSetAndGetWithIntBackedEnum()
    {
        CoroutineContext::set(ContextKeyIntBackedEnum::UserId, 'user-123');

        $this->assertSame('user-123', CoroutineContext::get(ContextKeyIntBackedEnum::UserId));
    }

    public function testHasWithBackedEnum()
    {
        $this->assertFalse(CoroutineContext::has(ContextKeyBackedEnum::CurrentUser));

        CoroutineContext::set(ContextKeyBackedEnum::CurrentUser, 'user-123');

        $this->assertTrue(CoroutineContext::has(ContextKeyBackedEnum::CurrentUser));
    }

    public function testHasWithUnitEnum()
    {
        $this->assertFalse(CoroutineContext::has(ContextKeyUnitEnum::Locale));

        CoroutineContext::set(ContextKeyUnitEnum::Locale, 'en-US');

        $this->assertTrue(CoroutineContext::has(ContextKeyUnitEnum::Locale));
    }

    public function testForgetWithBackedEnum()
    {
        CoroutineContext::set(ContextKeyBackedEnum::CurrentUser, 'user-123');
        $this->assertTrue(CoroutineContext::has(ContextKeyBackedEnum::CurrentUser));

        CoroutineContext::forget(ContextKeyBackedEnum::CurrentUser);

        $this->assertFalse(CoroutineContext::has(ContextKeyBackedEnum::CurrentUser));
    }

    public function testForgetWithUnitEnum()
    {
        CoroutineContext::set(ContextKeyUnitEnum::Locale, 'en-US');
        $this->assertTrue(CoroutineContext::has(ContextKeyUnitEnum::Locale));

        CoroutineContext::forget(ContextKeyUnitEnum::Locale);

        $this->assertFalse(CoroutineContext::has(ContextKeyUnitEnum::Locale));
    }

    public function testOverrideWithBackedEnum()
    {
        CoroutineContext::set(ContextKeyBackedEnum::CurrentUser, 'user-123');

        $result = CoroutineContext::override(ContextKeyBackedEnum::CurrentUser, fn ($value) => $value . '-modified');

        $this->assertSame('user-123-modified', $result);
        $this->assertSame('user-123-modified', CoroutineContext::get(ContextKeyBackedEnum::CurrentUser));
    }

    public function testOverrideWithUnitEnum()
    {
        CoroutineContext::set(ContextKeyUnitEnum::Locale, 'en');

        $result = CoroutineContext::override(ContextKeyUnitEnum::Locale, fn ($value) => $value . '-US');

        $this->assertSame('en-US', $result);
        $this->assertSame('en-US', CoroutineContext::get(ContextKeyUnitEnum::Locale));
    }

    public function testGetOrSetWithBackedEnum()
    {
        // First call should set and return the value
        $result = CoroutineContext::getOrSet(ContextKeyBackedEnum::RequestId, 'req-001');
        $this->assertSame('req-001', $result);

        // Second call should return existing value, not set new one
        $result = CoroutineContext::getOrSet(ContextKeyBackedEnum::RequestId, 'req-002');
        $this->assertSame('req-001', $result);
    }

    public function testGetOrSetWithUnitEnum()
    {
        $result = CoroutineContext::getOrSet(ContextKeyUnitEnum::Theme, 'dark');
        $this->assertSame('dark', $result);

        $result = CoroutineContext::getOrSet(ContextKeyUnitEnum::Theme, 'light');
        $this->assertSame('dark', $result);
    }

    public function testGetOrSetWithClosure()
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            ++$callCount;
            return 'computed-value';
        };

        $result = CoroutineContext::getOrSet(ContextKeyBackedEnum::Tenant, $callback);
        $this->assertSame('computed-value', $result);
        $this->assertSame(1, $callCount);

        // Closure should not be called again
        $result = CoroutineContext::getOrSet(ContextKeyBackedEnum::Tenant, $callback);
        $this->assertSame('computed-value', $result);
        $this->assertSame(1, $callCount);
    }

    public function testSetManyWithEnumKeys()
    {
        CoroutineContext::setMany([
            ContextKeyBackedEnum::CurrentUser->value => 'user-123',
            ContextKeyUnitEnum::Locale->name => 'en-US',
        ]);

        $this->assertSame('user-123', CoroutineContext::get(ContextKeyBackedEnum::CurrentUser));
        $this->assertSame('en-US', CoroutineContext::get(ContextKeyUnitEnum::Locale));
    }

    public function testBackedEnumAndStringInteroperability()
    {
        // Set with enum
        CoroutineContext::set(ContextKeyBackedEnum::CurrentUser, 'user-123');

        // Get with string (the enum value)
        $this->assertSame('user-123', CoroutineContext::get('current-user'));

        // Set with string
        CoroutineContext::set('request-id', 'req-456');

        // Get with enum
        $this->assertSame('req-456', CoroutineContext::get(ContextKeyBackedEnum::RequestId));
    }

    public function testUnitEnumAndStringInteroperability()
    {
        // Set with enum
        CoroutineContext::set(ContextKeyUnitEnum::Locale, 'en-US');

        // Get with string (the enum name)
        $this->assertSame('en-US', CoroutineContext::get('Locale'));

        // Set with string
        CoroutineContext::set('Theme', 'dark');

        // Get with enum
        $this->assertSame('dark', CoroutineContext::get(ContextKeyUnitEnum::Theme));
    }

    public function testGetWithDefaultAndBackedEnum()
    {
        $result = CoroutineContext::get(ContextKeyBackedEnum::CurrentUser, 'default-user');

        $this->assertSame('default-user', $result);
    }

    public function testGetWithDefaultAndUnitEnum()
    {
        $result = CoroutineContext::get(ContextKeyUnitEnum::Locale, 'en');

        $this->assertSame('en', $result);
    }

    public function testMultipleEnumKeysCanCoexist()
    {
        CoroutineContext::set(ContextKeyBackedEnum::CurrentUser, 'user-123');
        CoroutineContext::set(ContextKeyBackedEnum::RequestId, 'req-456');
        CoroutineContext::set(ContextKeyBackedEnum::Tenant, 'tenant-789');
        CoroutineContext::set(ContextKeyUnitEnum::Locale, 'en-US');
        CoroutineContext::set(ContextKeyUnitEnum::Theme, 'dark');

        $this->assertSame('user-123', CoroutineContext::get(ContextKeyBackedEnum::CurrentUser));
        $this->assertSame('req-456', CoroutineContext::get(ContextKeyBackedEnum::RequestId));
        $this->assertSame('tenant-789', CoroutineContext::get(ContextKeyBackedEnum::Tenant));
        $this->assertSame('en-US', CoroutineContext::get(ContextKeyUnitEnum::Locale));
        $this->assertSame('dark', CoroutineContext::get(ContextKeyUnitEnum::Theme));
    }
}
