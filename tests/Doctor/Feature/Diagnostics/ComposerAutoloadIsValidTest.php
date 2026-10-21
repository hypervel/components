<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\ComposerAutoloadIsValid;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Process;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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
    public function testChecksComposerAutoloadValidityWithAStrictDryRun(string $directory): void
    {
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
            '--optimize',
            '--strict-psr',
            '--strict-ambiguous',
            '--no-interaction',
        ] && $process->path === $basePath);

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('does not comply with psr-4', $result->details);
        $this->assertSame('Regenerate Composer autoload files using `composer dump-autoload`?', $result->confirmation);
        $this->assertSame('Regenerate Composer autoload files with `composer dump-autoload`.', $result->remediation);
    }

    /**
     * Provide conventional and relocated vendor directories.
     */
    public static function vendorDirectories(): array
    {
        return [['vendor'], ['dependencies']];
    }

    public function testRegeneratesComposerAutoloadFilesWhenFixed(): void
    {
        $basePath = $this->basePath;
        file_put_contents($basePath . '/composer.json', '{}');

        $this->app->setBasePath($basePath);

        Process::fake([
            '*' => Process::result(output: 'Generated autoload files'),
        ]);

        $fix = (new ComposerAutoloadIsValid)->fix(DiagnosticResult::fail('autoload'));

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            'composer',
            'dump-autoload',
            '--optimize',
            '--strict-psr',
            '--strict-ambiguous',
            '--no-interaction',
        ] && $process->path === $basePath);

        $this->assertSame('pass', $fix->status->value);
    }
}
