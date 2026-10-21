<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Sentry\Version;
use Hypervel\Tests\TestCase;
use Mockery as m;

class VersionTest extends TestCase
{
    public function testSdkVersionIsTheInstalledPackageVersion(): void
    {
        $manifest = m::mock(PackageManifest::class);
        $manifest->shouldReceive('version')
            ->once()
            ->with('hypervel/sentry')
            ->andReturn('1.2.3');

        Container::getInstance()->singleton(PackageManifest::class, fn () => $manifest);

        $this->assertSame('1.2.3', Version::getSdkVersion());
    }

    public function testSdkVersionFallsBackToTheFrameworkVersion(): void
    {
        $manifest = m::mock(PackageManifest::class);
        $manifest->shouldReceive('version')
            ->once()
            ->with('hypervel/sentry')
            ->andReturn(null);

        Container::getInstance()->singleton(PackageManifest::class, fn () => $manifest);

        $this->assertSame(Application::VERSION, Version::getSdkVersion());
    }
}
