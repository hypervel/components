<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\DebugModeMatchesEnvironment;
use Hypervel\Doctor\Doctor;
use Hypervel\Doctor\Results\DiagnosticOutcome;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;

class DebugModeMatchesEnvironmentTest extends TestCase
{
    private string $environmentPath;

    /**
     * Create an isolated environment directory.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->environmentPath = ParallelTesting::tempDir('DoctorDebugMode');
        File::deleteDirectory($this->environmentPath);
        File::makeDirectory($this->environmentPath, 0775, true);
        $this->app->useEnvironmentPath($this->environmentPath);
    }

    /**
     * Remove the environment fixture.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->environmentPath);
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

        $this->app->useEnvironmentPath($environmentPath);
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => true]);

        $diagnostic = new DebugModeMatchesEnvironment;

        $fix = $this->app->make(Doctor::class)->fix(new DiagnosticOutcome(
            $diagnostic,
            $diagnostic->check(),
        ));

        $this->assertSame('pass', $fix->result->status->value);
        $this->assertSame('Debug mode was disabled in the application environment file.', $fix->result->summary);
        $this->assertStringContainsString('APP_DEBUG=false', file_get_contents($environmentPath . '/.env'));
        $this->assertFalse(config('app.debug'));
    }

    public function testAddsAppDebugWhenItIsMissingFromTheApplicationEnvironmentFile(): void
    {
        $environmentPath = $this->environmentPath;
        file_put_contents($environmentPath . '/.env', "APP_NAME=Hypervel\n");

        $this->app->useEnvironmentPath($environmentPath);
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => true]);

        $diagnostic = new DebugModeMatchesEnvironment;

        $this->app->make(Doctor::class)->fix(new DiagnosticOutcome(
            $diagnostic,
            $diagnostic->check(),
        ));

        $this->assertStringContainsString('APP_DEBUG=false', file_get_contents($environmentPath . '/.env'));
    }
}
