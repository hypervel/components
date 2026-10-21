<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Doctor\Diagnostics\ApplicationKeyIsSet;
use Hypervel\Doctor\Doctor;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticOutcome;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;

class ApplicationKeyIsSetTest extends TestCase
{
    private string $environmentPath;

    /**
     * Configure the diagnostic environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->detectEnvironment(fn (): string => 'local');
    }

    /**
     * Create an isolated environment directory.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->environmentPath = ParallelTesting::tempDir('DoctorApplicationKey');
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

    public function testPassesWhenTheApplicationKeyIsSet(): void
    {
        config(['app.key' => 'base64:' . str_repeat('a', 44)]);

        $report = $this->app->make(Doctor::class)
            ->only('ApplicationKeyIsSet')->run();

        $outcome = $report->diagnostics()[0];

        $this->assertSame('pass', $outcome->result->status->value);
        $this->assertSame('The application key is configured.', $outcome->result->summary);
    }

    public function testReportsAMissingApplicationKey(): void
    {
        config(['app.key' => '']);

        $report = $this->app->make(Doctor::class)
            ->only('ApplicationKeyIsSet')->run();

        $outcome = $report->diagnostics()[0];

        $this->assertSame('fail', $outcome->result->status->value);
        $this->assertSame('application-key-is-set.missing', $outcome->result->code);
        $this->assertSame('The application key is not configured.', $outcome->result->summary);
        $this->assertSame('Generate an application key?', $outcome->result->confirmation);
        $this->assertSame('Generate an application key with `php artisan key:generate`.', $outcome->result->remediation);
        $this->assertSame([EnvironmentMode::Local], $outcome->result->fixableEnvironments);
        $this->assertTrue($outcome->fixable());
        $this->assertEquals([Link::docs('encryption')], $outcome->result->links);
    }

    public function testDoesNotOfferToGenerateAMissingApplicationKeyInProduction(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.key' => '']);

        $outcome = $this->app->make(Doctor::class)
            ->only('ApplicationKeyIsSet')->run()
            ->diagnostics()[0];

        $this->assertSame('fail', $outcome->result->status->value);
        $this->assertFalse($outcome->fixable());
        $this->assertSame('Generate an application key with `php artisan key:generate`.', $outcome->result->remediation);
    }

    public function testGeneratesAnApplicationKeyWhenFixed(): void
    {
        config(['app.key' => '']);

        File::put($this->environmentPath . '/.env', "APP_KEY=\n");

        $fix = $this->app->make(Doctor::class)->fix(new DiagnosticOutcome(
            new ApplicationKeyIsSet,
            DiagnosticResult::fail('The application key is not configured.')->fixable(),
        ));

        $this->assertSame('pass', $fix->result->status->value);
        $this->assertSame('application-key-is-set.fix.generated', $fix->result->code);
        $this->assertSame('The application key was generated.', $fix->result->summary);
        $this->assertStringContainsString('base64:', File::get($this->environmentPath . '/.env'));
        $this->assertStringStartsWith('base64:', config()->string('app.key'));
    }

    public function testAddsAnApplicationKeyWhenTheEnvironmentFileHasNoAppKeyVariable(): void
    {
        config(['app.key' => '']);

        File::put($this->environmentPath . '/.env', "APP_NAME=Hypervel\n");

        $fix = $this->app->make(Doctor::class)->fix(new DiagnosticOutcome(
            new ApplicationKeyIsSet,
            DiagnosticResult::fail('The application key is not configured.')->fixable(),
        ));

        $this->assertSame('pass', $fix->result->status->value);
        $this->assertSame('application-key-is-set.fix.generated', $fix->result->code);
        $contents = File::get($this->environmentPath . '/.env');
        $this->assertStringContainsString("APP_NAME=Hypervel\n", $contents);
        $this->assertStringContainsString('APP_KEY=', $contents);
        $this->assertStringContainsString('base64:', $contents);
    }

    public function testReportsWhenTheApplicationKeyCannotBeWrittenToTheEnvironmentFile(): void
    {
        $fix = $this->app->make(Doctor::class)->fix(new DiagnosticOutcome(
            new ApplicationKeyIsSet,
            DiagnosticResult::fail('The application key is not configured.')->fixable(),
        ));

        $this->assertSame('fail', $fix->result->status->value);
        $this->assertSame('application-key-is-set.fix.generation-failed', $fix->result->code);
        $this->assertSame('The application key could not be generated.', $fix->result->summary);
        $this->assertStringContainsString('could not be updated', $fix->result->details);
    }
}
