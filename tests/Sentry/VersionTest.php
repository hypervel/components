<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Sentry\Version;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class VersionTest extends TestCase
{
    use VerifiesDoubles;

    public function testSdkVersionIsTheInstalledPackageVersion(): void
    {
        $manifest = Double::for(PackageManifest::class)->strict();
        $manifest->expects('version')
            ->with('hypervel/sentry')
            ->returns('1.2.3');

        Container::getInstance()->singleton(PackageManifest::class, fn () => $manifest);

        $this->assertSame('1.2.3', Version::getSdkVersion());
    }

    public function testSdkVersionFallsBackToTheFrameworkVersion(): void
    {
        $manifest = Double::for(PackageManifest::class)->strict();
        $manifest->expects('version')
            ->with('hypervel/sentry')
            ->returns(null);

        Container::getInstance()->singleton(PackageManifest::class, fn () => $manifest);

        $this->assertSame(Application::VERSION, Version::getSdkVersion());
    }
}
