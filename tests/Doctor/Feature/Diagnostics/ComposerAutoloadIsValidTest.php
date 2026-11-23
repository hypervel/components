<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Composer\Autoload\ClassLoader;
use Hypervel\Doctor\Diagnostics\ComposerAutoloadIsValid;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Process\PendingProcess;
use Hypervel\Process\ProcessResult;
use Hypervel\Support\Composer;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Process;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process as SymfonyProcess;

class ComposerAutoloadIsValidTest extends TestCase
{
    private string $basePath;

    /**
     * Create an isolated Composer directory.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = ParallelTesting::tempDir('DoctorComposerAutoload');
        File::deleteDirectory($this->basePath);
        File::makeDirectory($this->basePath, 0775, true);
        $this->app->setBasePath($this->basePath);
    }

    /**
     * Remove the Composer fixture.
     */
    protected function tearDown(): void
    {
        try {
            File::deleteDirectory($this->basePath);
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('vendorDirectories')]
    public function testChecksComposerAutoloadValidityWithAStrictDryRun(string $directory, bool $authoritative, string $option): void
    {
        $loader = new ClassLoader;
        $loader->setClassMapAuthoritative($authoritative);
        Composer::setLoader($loader);
        $basePath = $this->basePath;
        mkdir($basePath . '/' . $directory);
        file_put_contents($basePath . '/composer.json', '{}');
        file_put_contents($basePath . '/' . $directory . '/autoload.php', '<?php');

        $this->app->make(PackageManifest::class)->vendorPath = $basePath . '/' . $directory;

        Process::fake([
            '*' => Process::result(
                output: 'Class App\Foo located in ./app/Bar.php does not comply with psr-4 autoloading standard.',
                exitCode: 1,
            ),
        ]);

        $result = (new ComposerAutoloadIsValid)->check();

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            'composer',
            'dump-autoload',
            '--dry-run',
            '--no-scripts',
            '--optimize',
            '--strict-psr',
            '--strict-ambiguous',
            '--no-interaction',
        ] && $process->path === $basePath);

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('does not comply with psr-4', $result->details);
        $this->assertSame("Regenerate Composer autoload files using `composer dump-autoload {$option}`?", $result->confirmation);
        $this->assertSame("Regenerate Composer autoload files with `composer dump-autoload {$option}`.", $result->remediation);
    }

    /**
     * Provide vendor directories and current autoloader modes.
     */
    public static function vendorDirectories(): array
    {
        return [
            ['vendor', false, '--optimize'],
            ['dependencies', false, '--optimize'],
            ['vendor', true, '--classmap-authoritative'],
        ];
    }

    #[TestWith([false, '--optimize'])]
    #[TestWith([true, '--classmap-authoritative'])]
    public function testRegeneratesComposerAutoloadFilesWhenFixed(bool $authoritative, string $option): void
    {
        $loader = new ClassLoader;
        $loader->setClassMapAuthoritative($authoritative);
        Composer::setLoader($loader);
        $basePath = $this->basePath;
        file_put_contents($basePath . '/composer.json', '{}');

        Process::fake([
            '*' => Process::result(output: 'Generated autoload files'),
        ]);

        $fix = (new ComposerAutoloadIsValid)->fix(DiagnosticResult::fail('autoload'));

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            'composer',
            'dump-autoload',
            $option,
            '--strict-psr',
            '--strict-ambiguous',
            '--no-interaction',
        ] && $process->path === $basePath && $process->timeout === null);

        $this->assertSame('pass', $fix->status->value);
    }

    public function testExplainsComposerFailuresWithoutOutput(): void
    {
        $basePath = $this->basePath;
        mkdir($basePath . '/vendor');
        file_put_contents($basePath . '/composer.json', '{}');
        file_put_contents($basePath . '/vendor/autoload.php', '<?php');

        $this->app->make(PackageManifest::class)->vendorPath = $basePath . '/vendor';

        Process::fake([
            '*' => Process::result(exitCode: 137),
        ]);

        $check = (new ComposerAutoloadIsValid)->check();
        $fix = (new ComposerAutoloadIsValid)->fix($check);

        $this->assertSame('fail', $check->status->value);
        $this->assertSame('Composer exited with code 137 without output.', $check->details);
        $this->assertSame('Composer exited with code 137 without output.', $fix->details);
    }

    public function testReportsStandardOutputWhenErrorOutputIsBlank(): void
    {
        $basePath = $this->basePath;
        mkdir($basePath . '/vendor');
        file_put_contents($basePath . '/composer.json', '{}');
        file_put_contents($basePath . '/vendor/autoload.php', '<?php');

        $this->app->make(PackageManifest::class)->vendorPath = $basePath . '/vendor';

        Process::fake([
            '*' => Process::result(
                output: 'Class App\Foo does not comply with psr-4 autoloading standard.',
                errorOutput: '  ',
                exitCode: 1,
            ),
        ]);

        $check = (new ComposerAutoloadIsValid)->check();

        $this->assertSame('fail', $check->status->value);
        $this->assertSame('Class App\Foo does not comply with psr-4 autoloading standard.', $check->details);
    }

    public function testExplainsComposerFailuresWithoutOutputOrExitCode(): void
    {
        $basePath = $this->basePath;
        mkdir($basePath . '/vendor');
        file_put_contents($basePath . '/composer.json', '{}');
        file_put_contents($basePath . '/vendor/autoload.php', '<?php');

        $this->app->make(PackageManifest::class)->vendorPath = $basePath . '/vendor';

        $result = new class(new SymfonyProcess(['composer'])) extends ProcessResult {
            /**
             * Determine if the process was successful.
             */
            public function successful(): bool
            {
                return false;
            }

            /**
             * Get the exit code of the process.
             */
            public function exitCode(): ?int
            {
                return null;
            }

            /**
             * Get the standard output of the process.
             */
            public function output(): string
            {
                return '';
            }

            /**
             * Get the error output of the process.
             */
            public function errorOutput(): string
            {
                return '';
            }
        };

        Process::fake(['*' => fn (): ProcessResult => $result]);

        $check = (new ComposerAutoloadIsValid)->check();
        $fix = (new ComposerAutoloadIsValid)->fix($check);

        $this->assertSame('fail', $check->status->value);
        $this->assertSame('Composer failed without output or an exit code.', $check->details);
        $this->assertSame('Composer failed without output or an exit code.', $fix->details);
    }
}
