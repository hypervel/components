<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client\Destinations;

use Hypervel\Http\Client\Destinations\CurlCapabilities;
use Hypervel\Http\Client\Destinations\DestinationPolicyException;
use Hypervel\Tests\TestCase;

class CurlCapabilitiesTest extends TestCase
{
    public function testAcceptsTheInstalledCurlTransport(): void
    {
        CurlCapabilities::ensurePinningSupported();

        $this->addToAssertionCount(1);
    }

    public function testRejectsAnUnusableCurlVersionResult(): void
    {
        FakeCurlCapabilities::useVersionInfo(false);

        $this->expectException(DestinationPolicyException::class);
        $this->expectExceptionMessage('usable cURL extension and curl_version() result');
        $this->expectExceptionMessage('libcurl version is [unavailable]');

        CurlCapabilities::ensurePinningSupported();
    }

    public function testAcceptsTheNonPermanentResolveFloor(): void
    {
        FakeCurlCapabilities::useVersionInfo([
            'version' => '7.75.0',
            'features' => CURL_VERSION_SSL,
        ]);

        CurlCapabilities::ensurePinningSupported();

        $this->addToAssertionCount(1);
    }

    public function testRejectsCurlBeforeTheNonPermanentResolveFloor(): void
    {
        FakeCurlCapabilities::useVersionInfo([
            'version' => '7.74.0',
            'features' => CURL_VERSION_SSL,
        ]);

        $this->expectException(DestinationPolicyException::class);
        $this->expectExceptionMessage('libcurl 7.75.0 or newer');
        $this->expectExceptionMessage('libcurl version is [7.74.0]');

        CurlCapabilities::ensurePinningSupported();
    }

    public function testRejectsCurlWithoutSslSupport(): void
    {
        FakeCurlCapabilities::useVersionInfo([
            'version' => '8.5.0',
            'features' => 0,
        ]);

        $this->expectException(DestinationPolicyException::class);
        $this->expectExceptionMessage('cURL SSL support');
        $this->expectExceptionMessage('libcurl version is [8.5.0]');

        CurlCapabilities::ensurePinningSupported();
    }

    public function testCachesASuccessfulPinningCheck(): void
    {
        CurlCapabilities::ensurePinningSupported();
        FakeCurlCapabilities::useVersionInfo(false);

        CurlCapabilities::ensurePinningSupported();

        $this->addToAssertionCount(1);
    }

    public function testRejectsAnHttpsProxyBeforeTheSupportedFloor(): void
    {
        FakeCurlCapabilities::useVersionInfo([
            'version' => '7.51.0',
            'features' => 1 << 21,
        ]);

        $this->expectException(DestinationPolicyException::class);
        $this->expectExceptionMessage('HTTPS proxy support for [https://proxy.example:8443]');
        $this->expectExceptionMessage('libcurl version is [7.51.0]');

        CurlCapabilities::ensureHttpsProxySupported('proxy.example', 8443);
    }

    public function testRejectsAnHttpsProxyWithoutTheFeatureBit(): void
    {
        FakeCurlCapabilities::useVersionInfo([
            'version' => '8.5.0',
            'features' => 0,
        ]);

        $this->expectException(DestinationPolicyException::class);
        $this->expectExceptionMessage('HTTPS proxy support for [https://[2001:db8::10]:443]');
        $this->expectExceptionMessage('libcurl version is [8.5.0]');

        CurlCapabilities::ensureHttpsProxySupported('[2001:db8::10]', 443);
    }
}

class FakeCurlCapabilities extends CurlCapabilities
{
    /**
     * Replace the process's libcurl version information.
     *
     * @param array{version: string, features: int}|false $versionInfo
     */
    public static function useVersionInfo(array|false $versionInfo): void
    {
        static::$versionInfo = $versionInfo;
    }
}
