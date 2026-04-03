<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Console;

use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Sentry\Hub;
use Hypervel\Sentry\Version;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Tests\Sentry\SentryTestCase;
use Sentry\Client;
use Sentry\State\HubInterface;

/**
 * @internal
 * @coversNothing
 */
class AboutCommandIntegrationTest extends SentryTestCase
{
    public function testAboutCommandContainsExpectedData(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.release' => '1.2.3',
            'sentry.environment' => 'testing',
            'sentry.traces_sample_rate' => 0.95,
        ]);

        $expectedData = [
            'environment' => 'testing',
            'release' => '1.2.3',
            'sample_rate_errors' => '100%',
            'sample_rate_profiling' => 'NOT SET',
            'sample_rate_performance_monitoring' => '95%',
            'send_default_pii' => 'DISABLED',
            'php_sdk_version' => Client::SDK_VERSION,
            'hypervel_sdk_version' => Version::SDK_VERSION,
        ];

        $actualData = $this->runArtisanAboutAndReturnSentryData();

        foreach ($expectedData as $key => $value) {
            $this->assertArrayHasKey($key, $actualData);
            $this->assertEquals($value, $actualData[$key]);
        }
    }

    public function testAboutCommandContainsExpectedDataWithoutHubClient(): void
    {
        $this->app->bind(HubInterface::class, static function () {
            return new Hub(null);
        });

        $expectedData = [
            'enabled' => 'NOT CONFIGURED',
            'php_sdk_version' => Client::SDK_VERSION,
            'hypervel_sdk_version' => Version::SDK_VERSION,
        ];

        $actualData = $this->runArtisanAboutAndReturnSentryData();

        foreach ($expectedData as $key => $value) {
            $this->assertArrayHasKey($key, $actualData);
            $this->assertEquals($value, $actualData[$key]);
        }
    }

    private function runArtisanAboutAndReturnSentryData(): array
    {
        $this->withoutMockingConsoleOutput();

        $this->artisan(AboutCommand::class, ['--json' => null]);

        $output = Artisan::output();

        // Refresh to ensure the command didn't have side effects on the container
        $this->refreshApplication();

        $aboutOutput = json_decode($output, true);

        $this->assertArrayHasKey('sentry', $aboutOutput);

        return $aboutOutput['sentry'];
    }
}
