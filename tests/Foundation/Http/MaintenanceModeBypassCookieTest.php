<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http;

use Hypervel\Foundation\Http\MaintenanceModeBypassCookie;
use Hypervel\Support\Carbon;
use Hypervel\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * @internal
 * @coversNothing
 */
class MaintenanceModeBypassCookieTest extends TestCase
{
    public function testCreateReturnsCookieInstance()
    {
        $cookie = MaintenanceModeBypassCookie::create('test-key');

        $this->assertInstanceOf(Cookie::class, $cookie);
    }

    public function testCookieHasCorrectName()
    {
        $cookie = MaintenanceModeBypassCookie::create('test-key');

        $this->assertSame('hypervel_maintenance', $cookie->getName());
    }

    public function testIsValidReturnsTrueForMatchingKey()
    {
        $cookie = MaintenanceModeBypassCookie::create('test-key');

        $this->assertTrue(MaintenanceModeBypassCookie::isValid($cookie->getValue(), 'test-key'));
    }

    public function testIsValidReturnsFalseForWrongKey()
    {
        $cookie = MaintenanceModeBypassCookie::create('test-key');

        $this->assertFalse(MaintenanceModeBypassCookie::isValid($cookie->getValue(), 'wrong-key'));
    }

    public function testIsValidReturnsFalseForExpiredCookie()
    {
        $cookie = MaintenanceModeBypassCookie::create('test-key');

        Carbon::setTestNow(now()->addMonths(6));

        $this->assertFalse(MaintenanceModeBypassCookie::isValid($cookie->getValue(), 'test-key'));
    }

    public function testIsValidReturnsFalseForInvalidPayload()
    {
        $this->assertFalse(MaintenanceModeBypassCookie::isValid('not-valid-base64-json', 'test-key'));
    }

    public function testIsValidReturnsFalseForMissingMac()
    {
        $payload = base64_encode(json_encode([
            'expires_at' => time() + 3600,
        ]));

        $this->assertFalse(MaintenanceModeBypassCookie::isValid($payload, 'test-key'));
    }

    public function testIsValidReturnsFalseForMissingExpiresAt()
    {
        $payload = base64_encode(json_encode([
            'mac' => 'some-mac-value',
        ]));

        $this->assertFalse(MaintenanceModeBypassCookie::isValid($payload, 'test-key'));
    }

    public function testCookieExpiresIn12Hours()
    {
        Carbon::setTestNow('2026-01-15 10:00:00');

        $cookie = MaintenanceModeBypassCookie::create('test-key');

        $this->assertSame(
            Carbon::parse('2026-01-15 22:00:00')->getTimestamp(),
            $cookie->getExpiresTime()
        );
    }
}
