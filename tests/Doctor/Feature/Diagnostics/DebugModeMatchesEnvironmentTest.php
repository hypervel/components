<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\DebugModeMatchesEnvironment;
use Hypervel\Doctor\Doctor;
use Hypervel\Doctor\Results\DiagnosticFixOutcome;
use Hypervel\Doctor\Results\DiagnosticOutcome;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Support\EnvironmentConfiguration;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Support\DotenvManager;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\File;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DebugModeMatchesEnvironmentTest extends TestCase
{
    private string $environmentPath;

    private ?string $originalEnvironmentFile;

    /**
     * Use the disposable application's environment file for fresh boots.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->environmentPath = $this->app->basePath();
        $path = $this->environmentPath . '/.env';
        $this->originalEnvironmentFile = is_file($path) ? File::get($path) : null;
        File::delete($path);
        $this->app->useEnvironmentPath($this->environmentPath);
    }

    /**
     * Remove the environment fixture.
     */
    protected function tearDown(): void
    {
        try {
            $path = $this->environmentPath . '/.env';
            $this->originalEnvironmentFile === null
                ? File::delete($path)
                : File::put($path, $this->originalEnvironmentFile);
        } finally {
            parent::tearDown();
        }
    }

    public function testReportsDebugModeInProduction(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => true]);

        $result = (new DebugModeMatchesEnvironment)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('Debug mode is enabled in production.', $result->summary);
        $this->assertTrue($result->fixable);
        $this->assertSame('Set APP_DEBUG=false in the application environment file?', $result->confirmation);
        $this->assertEquals([Link::docs('configuration', 'debug-mode')], $result->links);
    }

    public function testTreatsUnmappedEnvironmentsAsProduction(): void
    {
        $this->app->detectEnvironment(fn (): string => 'preview');
        config(['app.debug' => true]);

        $result = (new DebugModeMatchesEnvironment)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('Debug mode is enabled in production.', $result->summary);
    }

    public function testHonorsCustomLocalEnvironmentMappings(): void
    {
        $this->app->detectEnvironment(fn (): string => 'dev');
        config([
            'app.debug' => true,
            'doctor.environments.local' => ['local', 'dev'],
        ]);

        $result = (new DebugModeMatchesEnvironment)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame('Debug mode matches the application environment.', $result->summary);
    }

    public function testDisablesDebugModeInTheApplicationEnvironmentFile(): void
    {
        $environmentPath = $this->environmentPath;
        file_put_contents($environmentPath . '/.env', "APP_NAME=Hypervel\nAPP_DEBUG=true\n");

        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => true]);

        $fix = $this->fixDebugMode();

        $this->assertSame('pass', $fix->result->status->value);
        $this->assertSame('Debug mode was disabled in the application environment file.', $fix->result->summary);
        $this->assertStringContainsString('APP_DEBUG=false', file_get_contents($environmentPath . '/.env'));
        $this->assertFalse(config('app.debug'));
    }

    public function testAddsAppDebugWhenItIsMissingFromTheApplicationEnvironmentFile(): void
    {
        $environmentPath = $this->environmentPath;
        file_put_contents($environmentPath . '/.env', "APP_NAME=Hypervel\n");

        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => true]);

        $fix = $this->fixDebugMode();

        $this->assertSame('pass', $fix->result->status->value);
        $this->assertStringContainsString('APP_DEBUG=false', file_get_contents($environmentPath . '/.env'));
    }

    #[DataProvider('persistentRepairCases')]
    public function testRepairVerifiesFreshConfiguration(bool $cached, ?string $externalDebug, bool $loadDotenv): void
    {
        File::put($this->environmentPath . '/.env', "APP_DEBUG=true\n");
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => true]);

        if ($loadDotenv) {
            DotenvManager::flushState();
        }

        try {
            $this->withEnvironmentValues([
                'APP_DEBUG' => $externalDebug,
            ], function () use ($cached, $externalDebug, $loadDotenv): void {
                if ($externalDebug !== null) {
                    $_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = $externalDebug;
                }

                try {
                    if ($loadDotenv) {
                        DotenvManager::load([$this->environmentPath]);
                    }

                    if ($cached) {
                        $this->assertSame(0, Artisan::call('config:cache'));
                    }

                    $doctor = $this->app->make(Doctor::class);
                    $fix = $doctor->fix($doctor->check(new DebugModeMatchesEnvironment));

                    $this->assertSame($externalDebug === null ? 'pass' : 'fail', $fix->result->status->value);
                    $this->assertSame($externalDebug !== null, config('app.debug'));
                    $this->assertStringContainsString('APP_DEBUG=false', File::get($this->environmentPath . '/.env'));

                    if ($cached) {
                        $this->assertSame($externalDebug !== null, (require $this->app->getCachedConfigPath())['app']['debug']);
                    }
                } finally {
                    if ($loadDotenv) {
                        DotenvManager::flushState();
                    }
                }
            });
        } finally {
            File::delete($this->app->getCachedConfigPath());
        }
    }

    /**
     * Provide cached and externally overridden debug settings.
     */
    public static function persistentRepairCases(): array
    {
        return [
            'uncached' => [false, null, false],
            'cached' => [true, null, false],
            'external override' => [false, 'true', false],
            'cached external override' => [true, 'true', false],
            'uncached dotenv loaded' => [false, null, true],
            'cache built after dotenv load' => [true, null, true],
            'cache built with external override' => [true, 'true', true],
        ];
    }

    public function testRepairDoesNotRecreateACacheRemovedAfterBootstrap(): void
    {
        File::put($this->environmentPath . '/.env', "APP_DEBUG=true\n");

        try {
            $this->withEnvironmentValue('APP_DEBUG', null, function (): void {
                $this->assertSame(0, Artisan::call('config:cache'));
                (new LoadConfiguration)->bootstrap($this->app);
                $this->assertTrue($this->app->configurationIsCached());
                Artisan::call('config:clear');
                File::put($this->environmentPath . '/.env', "APP_DEBUG=false\n");

                $failure = EnvironmentConfiguration::refresh('app.debug', false);

                $this->assertNull($failure);
                $this->assertFalse(config('app.debug'));
                $this->assertFileDoesNotExist($this->app->getCachedConfigPath());
            });
        } finally {
            File::delete($this->app->getCachedConfigPath());
        }
    }

    public function testVerificationDoesNotSelectAnotherFileUsingAnInheritedAppEnvironment(): void
    {
        $configPath = $this->app->configPath('app.php');
        $originalConfig = File::get($configPath);
        File::put($configPath, "<?php return ['debug' => (bool) env('DOCTOR_DEBUG', true)];\n");
        File::put($this->environmentPath . '/.env', "APP_ENV=doctor\nAPP_DEBUG=true\n");
        File::put($this->environmentPath . '/.env.doctor', "DOCTOR_DEBUG=false\n");
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => true]);
        DotenvManager::flushState();

        try {
            $this->withEnvironmentValues(['APP_ENV' => null, 'APP_DEBUG' => null, 'DOCTOR_DEBUG' => null], function (): void {
                try {
                    DotenvManager::load([$this->environmentPath]);

                    $fix = $this->fixDebugMode();

                    $this->assertSame('fail', $fix->result->status->value);
                    $this->assertTrue(config('app.debug'));
                    $this->assertStringContainsString('different value', $fix->result->details);
                } finally {
                    DotenvManager::flushState();
                }
            });
        } finally {
            File::put($configPath, $originalConfig);
            File::delete($this->environmentPath . '/.env.doctor');
        }
    }

    /**
     * Repair through the diagnostic with an independently bootable application.
     */
    private function fixDebugMode(): DiagnosticFixOutcome
    {
        return $this->withEnvironmentValues([
            'APP_DEBUG' => null,
        ], function (): DiagnosticFixOutcome {
            $diagnostic = new DebugModeMatchesEnvironment;

            return $this->app->make(Doctor::class)->fix(new DiagnosticOutcome($diagnostic, $diagnostic->check()));
        });
    }
}
