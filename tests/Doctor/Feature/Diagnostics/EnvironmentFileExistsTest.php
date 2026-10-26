<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Doctor\Diagnostics\EnvironmentFileExists;
use Hypervel\Doctor\Doctor;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticOutcome;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\File;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;

class EnvironmentFileExistsTest extends TestCase
{
    private string $basePath;

    /**
     * Configure local maintenance behavior.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->detectEnvironment(fn (): string => 'local');
    }

    /**
     * Create an isolated, relocated environment directory.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = ParallelTesting::tempDir('DoctorEnvironmentFile');
        File::deleteDirectory($this->basePath);
        File::makeDirectory($this->basePath, 0775, true);
        $this->app->useEnvironmentPath($this->basePath);
        $this->app->loadEnvironmentFrom('.env');
    }

    /**
     * Remove the environment fixture.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->basePath);
        } finally {
            parent::tearDown();
        }
    }

    public function testPassesWhenEnvExists(): void
    {
        $basePath = $this->basePath;
        touch($basePath . '/.env');

        $report = $this->app->make(Doctor::class)
            ->only('EnvironmentFileExists')->run();

        $outcome = $report->diagnostics()[0];

        $this->assertSame('pass', $outcome->result->status->value);
        $this->assertSame("The selected environment file [{$basePath}/.env] exists.", $outcome->result->summary);
    }

    public function testPassesWhenTheSelectedEnvironmentSpecificFileExists(): void
    {
        $basePath = $this->basePath;
        touch($basePath . '/.env.testing');

        $this->app->loadEnvironmentFrom('.env.testing');

        $report = $this->app->make(Doctor::class)
            ->only('EnvironmentFileExists')->run();

        $this->assertSame('pass', $report->diagnostics()[0]->result->status->value);
    }

    public function testReportsAMissingEnvironmentFileWithGuidanceWhenAnExampleExists(): void
    {
        $basePath = $this->basePath;
        file_put_contents($basePath . '/.env.example', "APP_NAME=Hypervel\n");

        $report = $this->app->make(Doctor::class)
            ->only('EnvironmentFileExists')->run();

        $outcome = $report->diagnostics()[0];

        $this->assertSame('fail', $outcome->result->status->value);
        $this->assertSame("The selected environment file [{$basePath}/.env] does not exist.", $outcome->result->summary);
        $this->assertSame("Copy [{$basePath}/.env.example] to [{$basePath}/.env]?", $outcome->result->confirmation);
        $this->assertSame("Copy [{$basePath}/.env.example] to [{$basePath}/.env], then review the copied values.", $outcome->result->remediation);
        $this->assertSame([EnvironmentMode::Local], $outcome->result->fixableEnvironments);
        $this->assertTrue($outcome->fixable());
        $this->assertEquals([Link::docs('configuration', 'environment-configuration')], $outcome->result->links);
    }

    public function testDoesNotOfferToCreateAMissingEnvironmentFileInProduction(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $basePath = $this->basePath;
        file_put_contents($basePath . '/.env.example', "APP_NAME=Hypervel\n");

        $outcome = $this->app->make(Doctor::class)
            ->only('EnvironmentFileExists')->run()
            ->diagnostics()[0];

        $this->assertSame('skip', $outcome->result->status->value);
        $this->assertFalse($outcome->fixable());
        $this->assertNull($outcome->result->remediation);
    }

    public function testCopiesEnvExampleToEnvWhenFixed(): void
    {
        $basePath = $this->basePath;
        file_put_contents($basePath . '/.env.example', "APP_NAME=Hypervel\n");

        $diagnostic = new EnvironmentFileExists;

        $fix = $this->app->make(Doctor::class)->fix(new DiagnosticOutcome(
            $diagnostic,
            $diagnostic->check(),
        ));

        $this->assertSame('pass', $fix->result->status->value);
        $this->assertSame("The environment file [{$basePath}/.env] was created from [{$basePath}/.env.example].", $fix->result->summary);
        $this->assertSame("APP_NAME=Hypervel\n", file_get_contents($basePath . '/.env'));
    }

    public function testDoesNotOverwriteAnExistingEnvWhenFixed(): void
    {
        $basePath = $this->basePath;
        file_put_contents($basePath . '/.env.example', "APP_NAME=Example\n");

        $diagnostic = new EnvironmentFileExists;
        $result = $diagnostic->check();

        file_put_contents($basePath . '/.env', "APP_NAME=Existing\n");

        $fix = $this->app->make(Doctor::class)->fix(new DiagnosticOutcome(
            $diagnostic,
            $result,
        ));

        $this->assertSame('pass', $fix->result->status->value);
        $this->assertSame("APP_NAME=Existing\n", file_get_contents($basePath . '/.env'));
    }

    public function testCreatesAnEmptyEnvWhenEnvExampleIsMissing(): void
    {
        $basePath = $this->basePath;

        $diagnostic = new EnvironmentFileExists;
        $result = $diagnostic->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertTrue($result->fixable);
        $this->assertSame([EnvironmentMode::Local], $result->fixableEnvironments);
        $this->assertSame("Create an empty environment file at [{$basePath}/.env]?", $result->confirmation);
        $this->assertSame("Create [{$basePath}/.env] with the application's environment variables.", $result->remediation);
        $this->assertEquals([Link::docs('configuration', 'environment-configuration')], $result->links);

        $fix = $this->app->make(Doctor::class)->fix(new DiagnosticOutcome(
            $diagnostic,
            $result,
        ));

        $this->assertSame('pass', $fix->result->status->value);
        $this->assertSame("An empty environment file [{$basePath}/.env] was created.", $fix->result->summary);
        $this->assertSame('', file_get_contents($basePath . '/.env'));
    }

    public function testChecksAndRepairsOnlyTheSelectedFile(): void
    {
        file_put_contents($this->basePath . '/.env', 'APP_NAME=Unselected');
        file_put_contents($this->basePath . '/.env.other', 'APP_NAME=Other');
        file_put_contents($this->basePath . '/.env.example', 'APP_NAME=Selected');
        $this->app->loadEnvironmentFrom('.env.custom');
        $diagnostic = new EnvironmentFileExists;
        $result = $diagnostic->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('pass', $diagnostic->fix($result)->status->value);
        $this->assertSame('APP_NAME=Selected', file_get_contents($this->basePath . '/.env.custom'));
        $this->assertSame('APP_NAME=Unselected', file_get_contents($this->basePath . '/.env'));
        $this->assertSame('APP_NAME=Other', file_get_contents($this->basePath . '/.env.other'));
    }
}
