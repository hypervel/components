<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Facade;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

class TimezoneTest extends TestCase
{
    #[Override]
    protected function getApplicationTimezone(ApplicationContract $app): ?string
    {
        return 'Asia/Kuala_Lumpur';
    }

    #[Test]
    public function itCanOverrideTimezone(): void
    {
        $this->assertSame('Asia/Kuala_Lumpur', CarbonImmutable::now()->timezoneName);
    }

    #[Test]
    public function itRestoresTheExactTimezoneWhenTheApplicationIsDestroyed(): void
    {
        $originalTimezone = date_default_timezone_get();
        $testCase = new TimezoneLifecycleTestCaseFixture('testPlaceholder');

        try {
            date_default_timezone_set('Europe/London');

            $testCase->createTestApplication();

            $this->assertSame('Asia/Kuala_Lumpur', date_default_timezone_get());

            $testCase->destroyTestApplication();

            $this->assertSame('Europe/London', date_default_timezone_get());
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[Test]
    public function itKeepsTheConfiguredTimezoneForAStandaloneApplicationLifetime(): void
    {
        $originalTimezone = date_default_timezone_get();
        $testbench = new StandaloneTimezoneApplicationFixture($this->app->basePath());
        $app = null;

        try {
            date_default_timezone_set('Europe/London');

            $app = $testbench->createApplication();

            $this->assertSame('Asia/Kuala_Lumpur', date_default_timezone_get());

            $app->terminate();

            $this->assertSame('Asia/Kuala_Lumpur', date_default_timezone_get());
        } finally {
            $app?->flush();
            Container::setInstance($this->app);
            Facade::setFacadeApplication($this->app);
            date_default_timezone_set($originalTimezone);
        }
    }
}

class TimezoneLifecycleTestCaseFixture extends TestCase
{
    public function testPlaceholder(): void
    {
    }

    public function createTestApplication(): void
    {
        $this->app = $this->createApplication();
    }

    public function destroyTestApplication(): void
    {
        $this->tearDownTheTestEnvironment();
    }

    #[Override]
    protected function getApplicationTimezone(ApplicationContract $app): ?string
    {
        return 'Asia/Kuala_Lumpur';
    }
}

class StandaloneTimezoneApplicationFixture extends TestbenchApplication
{
    #[Override]
    protected function getApplicationTimezone(ApplicationContract $app): ?string
    {
        return 'Asia/Kuala_Lumpur';
    }
}
