<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Doctor\Diagnostics\ApplicationKeyIsSet;
use Hypervel\Doctor\Doctor;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticFixOutcome;
use Hypervel\Doctor\Results\DiagnosticOutcome;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\File;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ApplicationKeyIsSetTest extends TestCase
{
    private string $environmentPath;

    private ?string $originalEnvironmentFile;

    /**
     * Configure the diagnostic environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->detectEnvironment(fn (): string => 'local');
    }

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

    #[DataProvider('keyRepairEnvironments')]
    public function testGeneratesAnApplicationKeyWhenFixed(bool $cached, bool $explicitEnvironment): void
    {
        config(['app.key' => '']);
        $arguments = $_SERVER['argv'];
        $_SERVER['argv'] = ['artisan', 'doctor', ...($explicitEnvironment ? ['--env=doctor'] : [])];
        File::put($this->environmentPath . '/.env', "APP_KEY=\n");

        if ($explicitEnvironment) {
            $this->app->loadEnvironmentFrom('.env.doctor');
            File::put($this->app->environmentFilePath(), "APP_KEY=\n");
        }

        try {
            $fix = $this->withEnvironmentValues([
                'APP_KEY' => null,
            ], function () use ($cached): DiagnosticFixOutcome {
                if ($cached) {
                    $this->assertSame(0, Artisan::call('config:cache'));
                }

                return $this->app->make(Doctor::class)->fix(new DiagnosticOutcome(
                    new ApplicationKeyIsSet,
                    DiagnosticResult::fail('The application key is not configured.')->fixable(),
                ));
            });

            $this->assertSame('pass', $fix->result->status->value, $fix->result->details ?? '');
            $this->assertSame('application-key-is-set.fix.generated', $fix->result->code);
            $this->assertSame('The application key was generated.', $fix->result->summary);
            $this->assertStringContainsString(config()->string('app.key'), File::get($this->app->environmentFilePath()));
            $this->assertStringStartsWith('base64:', config()->string('app.key'));

            if ($explicitEnvironment) {
                $this->assertSame("APP_KEY=\n", File::get($this->environmentPath . '/.env'));
            }

            if ($cached) {
                $this->assertSame(config('app.key'), (require $this->app->getCachedConfigPath())['app']['key']);
            }
        } finally {
            $_SERVER['argv'] = $arguments;
            File::delete($this->app->getCachedConfigPath());
            File::delete($this->environmentPath . '/.env.doctor');
        }
    }

    /**
     * Provide cached and explicitly selected environment files.
     */
    public static function keyRepairEnvironments(): array
    {
        return [
            'uncached' => [false, false],
            'cached' => [true, false],
            'explicit environment' => [false, true],
            'cached explicit environment' => [true, true],
        ];
    }

    public function testDoesNotPublishAGeneratedKeyThatFreshConfigurationIgnores(): void
    {
        config(['app.key' => '']);
        File::put($this->app->environmentFilePath(), "APP_KEY=\n");
        $configPath = $this->app->configPath('app.php');
        $original = File::get($configPath);
        File::put($configPath, "<?php return ['key' => 'external-key'];\n");

        try {
            $fix = $this->withEnvironmentValues([
                'APP_KEY' => null,
            ], fn () => (new ApplicationKeyIsSet)->fix(DiagnosticResult::fail('missing')));

            $this->assertSame('fail', $fix->status->value);
            $this->assertSame('external-key', config('app.key'));
            $this->assertStringContainsString('APP_KEY=base64:', File::get($this->app->environmentFilePath()));
            $this->assertStringNotContainsString('external-key', $fix->details);
            $this->assertStringNotContainsString('base64:', $fix->details);
        } finally {
            File::put($configPath, $original);
        }
    }

    public function testAddsAnApplicationKeyWhenTheEnvironmentFileHasNoAppKeyVariable(): void
    {
        config(['app.key' => '']);

        File::put($this->environmentPath . '/.env', "APP_NAME=Hypervel\n");

        $fix = $this->withEnvironmentValues([
            'APP_KEY' => null,
        ], fn () => $this->app->make(Doctor::class)->fix(new DiagnosticOutcome(
            new ApplicationKeyIsSet,
            DiagnosticResult::fail('The application key is not configured.')->fixable(),
        )));

        $this->assertSame('pass', $fix->result->status->value);
        $this->assertSame('application-key-is-set.fix.generated', $fix->result->code);
        $contents = File::get($this->environmentPath . '/.env');
        $this->assertStringContainsString("APP_NAME=Hypervel\n", $contents);
        $this->assertStringContainsString('APP_KEY=', $contents);
        $this->assertStringContainsString('base64:', $contents);
    }

    public function testReportsWhenTheApplicationKeyCannotBeWrittenToTheEnvironmentFile(): void
    {
        config(['app.key' => '']);

        $fix = $this->app->make(Doctor::class)->fix(new DiagnosticOutcome(
            new ApplicationKeyIsSet,
            DiagnosticResult::fail('The application key is not configured.')->fixable(),
        ));

        $this->assertSame('fail', $fix->result->status->value);
        $this->assertSame('application-key-is-set.fix.generation-failed', $fix->result->code);
        $this->assertSame('The application key could not be generated.', $fix->result->summary);
        $this->assertStringContainsString('could not be updated', $fix->result->details);
    }

    public function testAStaleFixDoesNotRotateAnExistingKey(): void
    {
        $key = 'base64:' . base64_encode(str_repeat('a', 32));
        config(['app.key' => $key]);
        File::put($this->environmentPath . '/.env', 'APP_KEY=' . $key);

        $fix = (new ApplicationKeyIsSet)->fix(DiagnosticResult::fail('missing'));

        $this->assertSame('pass', $fix->status->value);
        $this->assertSame($key, config('app.key'));
        $this->assertSame('APP_KEY=' . $key, File::get($this->environmentPath . '/.env'));
    }
}
