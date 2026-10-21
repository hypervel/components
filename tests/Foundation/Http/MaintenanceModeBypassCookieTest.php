<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http;

use Hypervel\Foundation\Http\MaintenanceModeBypassCookie;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Cookie;

class MaintenanceModeBypassCookieTest extends TestCase
{
    public function testCreateReturnsCookieInstance(): void
    {
        $cookie = MaintenanceModeBypassCookie::create('test-key');

        $this->assertInstanceOf(Cookie::class, $cookie);
    }

    public function testCookieHasCorrectName(): void
    {
        $cookie = MaintenanceModeBypassCookie::create('test-key');

        $this->assertSame('hypervel_maintenance', $cookie->getName());
    }

    public function testIsValidReturnsTrueForMatchingKey(): void
    {
        $cookie = MaintenanceModeBypassCookie::create('test-key');

        $this->assertTrue(MaintenanceModeBypassCookie::isValid($cookie->getValue(), 'test-key'));
    }

    public function testIsValidReturnsFalseForWrongKey(): void
    {
        $cookie = MaintenanceModeBypassCookie::create('test-key');

        $this->assertFalse(MaintenanceModeBypassCookie::isValid($cookie->getValue(), 'wrong-key'));
    }

    public function testIsValidReturnsFalseForExpiredCookie(): void
    {
        $cookie = MaintenanceModeBypassCookie::create('test-key');

        CarbonImmutable::setTestNow(now()->addMonths(6));

        $this->assertFalse(MaintenanceModeBypassCookie::isValid($cookie->getValue(), 'test-key'));
    }

    public function testIsValidReturnsFalseForInvalidPayload(): void
    {
        $this->assertFalse(MaintenanceModeBypassCookie::isValid('not-valid-base64-json', 'test-key'));
    }

    public function testIsValidReturnsFalseForMissingMac(): void
    {
        $payload = base64_encode(json_encode([
            'expires_at' => time() + 3600,
        ]));

        $this->assertFalse(MaintenanceModeBypassCookie::isValid($payload, 'test-key'));
    }

    public function testIsValidReturnsFalseForMissingExpiresAt(): void
    {
        $payload = base64_encode(json_encode([
            'mac' => 'some-mac-value',
        ]));

        $this->assertFalse(MaintenanceModeBypassCookie::isValid($payload, 'test-key'));
    }

    public function testCookieExpiresIn12Hours(): void
    {
        CarbonImmutable::setTestNow('2026-01-15 10:00:00');

        $cookie = MaintenanceModeBypassCookie::create('test-key');

        $this->assertSame(
            CarbonImmutable::parse('2026-01-15 22:00:00')->getTimestamp(),
            $cookie->getExpiresTime()
        );
    }
}
