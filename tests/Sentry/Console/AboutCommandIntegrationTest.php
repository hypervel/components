<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Console;

use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Sentry\Hub;
use Hypervel\Sentry\Version;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Tests\Sentry\TestCase;
use Sentry\Client;
use Sentry\State\HubInterface;

class AboutCommandIntegrationTest extends TestCase
{
    public function testAboutCommandContainsExpectedData(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.release' => '1.2.3',
            'sentry.environment' => 'testing',
            'sentry.traces_sample_rate' => 0.95,
        ]);

        $expectedData = [
            'data_collection' => 'NOT SET',
            'environment' => 'testing',
            'release' => '1.2.3',
            'sample_rate_errors' => '100%',
            'sample_rate_profiling' => 'NOT SET',
            'sample_rate_performance_monitoring' => '95%',
            'send_default_pii' => 'DISABLED',
            'php_sdk_version' => Client::SDK_VERSION,
            'hypervel_sdk_version' => Version::getSdkVersion(),
        ];

        $actualData = $this->runArtisanAboutAndReturnSentryData();

        foreach ($expectedData as $key => $value) {
            $this->assertArrayHasKey($key, $actualData);
            $this->assertEquals($value, $actualData[$key]);
        }
    }

    public function testAboutCommandShowsThatDataCollectionReplacesSendDefaultPii(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.send_default_pii' => true,
            'sentry.data_collection' => [],
        ]);

        $actualData = $this->runArtisanAboutAndReturnSentryData();

        $this->assertEquals('ENABLED', $actualData['data_collection'] ?? null);
        $this->assertEquals('IGNORED', $actualData['send_default_pii'] ?? null);
    }

    public function testAboutCommandRecognizesCustomProfileSampler(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.profiles_sampler' => static fn (): float => 1.0,
        ]);

        $actualData = $this->runArtisanAboutAndReturnSentryData();

        $this->assertSame('CUSTOM SAMPLER', $actualData['sample_rate_profiling']);
    }

    public function testAboutCommandContainsExpectedDataWithoutHubClient(): void
    {
        $this->app->bind(HubInterface::class, static function () {
            return new Hub(null);
        });

        $expectedData = [
            'enabled' => 'NOT CONFIGURED',
            'php_sdk_version' => Client::SDK_VERSION,
            'hypervel_sdk_version' => Version::getSdkVersion(),
        ];

        $actualData = $this->runArtisanAboutAndReturnSentryData();

        foreach ($expectedData as $key => $value) {
            $this->assertArrayHasKey($key, $actualData);
            $this->assertEquals($value, $actualData[$key]);
        }
    }

    /**
     * Run the `about` command and return its Sentry section.
     */
    private function runArtisanAboutAndReturnSentryData(): array
    {
        $this->withoutMockingConsoleOutput();

        $this->artisan(AboutCommand::class, ['--json' => null]);

        $output = Artisan::output();

        // This might seem like a weird thing to do, but it's necessary to make sure that the command didn't have any side effects on the container
        $this->reloadApplication();

        $aboutOutput = json_decode($output, true);

        $this->assertArrayHasKey('sentry', $aboutOutput);

        return $aboutOutput['sentry'];
    }
}
