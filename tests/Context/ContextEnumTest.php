<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\Context;
use PHPUnit\Framework\TestCase;

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

/**
 * @internal
 * @coversNothing
 */
class ContextEnumTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Context::destroyAll();
    }

    protected function tearDown(): void
    {
        Context::destroyAll();
        parent::tearDown();
    }

    public function testSetAndGetWithBackedEnum(): void
    {
        Context::set(ContextKeyBackedEnum::CurrentUser, 'user-123');

        $this->assertSame('user-123', Context::get(ContextKeyBackedEnum::CurrentUser));
    }

    public function testSetAndGetWithUnitEnum(): void
    {
        Context::set(ContextKeyUnitEnum::Locale, 'en-US');

        $this->assertSame('en-US', Context::get(ContextKeyUnitEnum::Locale));
    }

    public function testSetAndGetWithIntBackedEnum(): void
    {
        Context::set(ContextKeyIntBackedEnum::UserId, 'user-123');

        $this->assertSame('user-123', Context::get(ContextKeyIntBackedEnum::UserId));
    }

    public function testHasWithBackedEnum(): void
    {
        $this->assertFalse(Context::has(ContextKeyBackedEnum::CurrentUser));

        Context::set(ContextKeyBackedEnum::CurrentUser, 'user-123');

        $this->assertTrue(Context::has(ContextKeyBackedEnum::CurrentUser));
    }

    public function testHasWithUnitEnum(): void
    {
        $this->assertFalse(Context::has(ContextKeyUnitEnum::Locale));

        Context::set(ContextKeyUnitEnum::Locale, 'en-US');

        $this->assertTrue(Context::has(ContextKeyUnitEnum::Locale));
    }

    public function testDestroyWithBackedEnum(): void
    {
        Context::set(ContextKeyBackedEnum::CurrentUser, 'user-123');
        $this->assertTrue(Context::has(ContextKeyBackedEnum::CurrentUser));

        Context::destroy(ContextKeyBackedEnum::CurrentUser);

        $this->assertFalse(Context::has(ContextKeyBackedEnum::CurrentUser));
    }

    public function testDestroyWithUnitEnum(): void
    {
        Context::set(ContextKeyUnitEnum::Locale, 'en-US');
        $this->assertTrue(Context::has(ContextKeyUnitEnum::Locale));

        Context::destroy(ContextKeyUnitEnum::Locale);

        $this->assertFalse(Context::has(ContextKeyUnitEnum::Locale));
    }

    public function testOverrideWithBackedEnum(): void
    {
        Context::set(ContextKeyBackedEnum::CurrentUser, 'user-123');

        $result = Context::override(ContextKeyBackedEnum::CurrentUser, fn ($value) => $value . '-modified');

        $this->assertSame('user-123-modified', $result);
        $this->assertSame('user-123-modified', Context::get(ContextKeyBackedEnum::CurrentUser));
    }

    public function testOverrideWithUnitEnum(): void
    {
        Context::set(ContextKeyUnitEnum::Locale, 'en');

        $result = Context::override(ContextKeyUnitEnum::Locale, fn ($value) => $value . '-US');

        $this->assertSame('en-US', $result);
        $this->assertSame('en-US', Context::get(ContextKeyUnitEnum::Locale));
    }

    public function testGetOrSetWithBackedEnum(): void
    {
        // First call should set and return the value
        $result = Context::getOrSet(ContextKeyBackedEnum::RequestId, 'req-001');
        $this->assertSame('req-001', $result);

        // Second call should return existing value, not set new one
        $result = Context::getOrSet(ContextKeyBackedEnum::RequestId, 'req-002');
        $this->assertSame('req-001', $result);
    }

    public function testGetOrSetWithUnitEnum(): void
    {
        $result = Context::getOrSet(ContextKeyUnitEnum::Theme, 'dark');
        $this->assertSame('dark', $result);

        $result = Context::getOrSet(ContextKeyUnitEnum::Theme, 'light');
        $this->assertSame('dark', $result);
    }

    public function testGetOrSetWithClosure(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            ++$callCount;
            return 'computed-value';
        };

        $result = Context::getOrSet(ContextKeyBackedEnum::Tenant, $callback);
        $this->assertSame('computed-value', $result);
        $this->assertSame(1, $callCount);

        // Closure should not be called again
        $result = Context::getOrSet(ContextKeyBackedEnum::Tenant, $callback);
        $this->assertSame('computed-value', $result);
        $this->assertSame(1, $callCount);
    }

    public function testSetManyWithEnumKeys(): void
    {
        Context::setMany([
            ContextKeyBackedEnum::CurrentUser->value => 'user-123',
            ContextKeyUnitEnum::Locale->name => 'en-US',
        ]);

        $this->assertSame('user-123', Context::get(ContextKeyBackedEnum::CurrentUser));
        $this->assertSame('en-US', Context::get(ContextKeyUnitEnum::Locale));
    }

    public function testBackedEnumAndStringInteroperability(): void
    {
        // Set with enum
        Context::set(ContextKeyBackedEnum::CurrentUser, 'user-123');

        // Get with string (the enum value)
        $this->assertSame('user-123', Context::get('current-user'));

        // Set with string
        Context::set('request-id', 'req-456');

        // Get with enum
        $this->assertSame('req-456', Context::get(ContextKeyBackedEnum::RequestId));
    }

    public function testUnitEnumAndStringInteroperability(): void
    {
        // Set with enum
        Context::set(ContextKeyUnitEnum::Locale, 'en-US');

        // Get with string (the enum name)
        $this->assertSame('en-US', Context::get('Locale'));

        // Set with string
        Context::set('Theme', 'dark');

        // Get with enum
        $this->assertSame('dark', Context::get(ContextKeyUnitEnum::Theme));
    }

    public function testGetWithDefaultAndBackedEnum(): void
    {
        $result = Context::get(ContextKeyBackedEnum::CurrentUser, 'default-user');

        $this->assertSame('default-user', $result);
    }

    public function testGetWithDefaultAndUnitEnum(): void
    {
        $result = Context::get(ContextKeyUnitEnum::Locale, 'en');

        $this->assertSame('en', $result);
    }

    public function testMultipleEnumKeysCanCoexist(): void
    {
        Context::set(ContextKeyBackedEnum::CurrentUser, 'user-123');
        Context::set(ContextKeyBackedEnum::RequestId, 'req-456');
        Context::set(ContextKeyBackedEnum::Tenant, 'tenant-789');
        Context::set(ContextKeyUnitEnum::Locale, 'en-US');
        Context::set(ContextKeyUnitEnum::Theme, 'dark');

        $this->assertSame('user-123', Context::get(ContextKeyBackedEnum::CurrentUser));
        $this->assertSame('req-456', Context::get(ContextKeyBackedEnum::RequestId));
        $this->assertSame('tenant-789', Context::get(ContextKeyBackedEnum::Tenant));
        $this->assertSame('en-US', Context::get(ContextKeyUnitEnum::Locale));
        $this->assertSame('dark', Context::get(ContextKeyUnitEnum::Theme));
    }
}
