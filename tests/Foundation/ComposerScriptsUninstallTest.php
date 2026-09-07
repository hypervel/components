<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\BufferIO;
use Composer\Package\Package;
use Composer\Repository\ArrayRepository;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\ComposerScripts;
use Hypervel\Testbench\Attributes\UsesVendor;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Foundation\Fixtures\ComposerUninstallServiceProvider;
use Hypervel\Tests\Testing\Fixtures\CleanupActions;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\OutputInterface;

#[UsesVendor]
class ComposerScriptsUninstallTest extends TestCase
{
    // Composer invokes its scripts outside a coroutine.
    protected bool $runTestsInCoroutine = false;

    protected Filesystem $files;

    protected Composer $composer;

    protected string $providersPath;

    protected string $originalProviders;

    protected string $marker;

    protected string $previousDirectory;

    /**
     * Prepare a package listener in the disposable application.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->providersPath = $this->app->getBootstrapProvidersPath();
        $this->originalProviders = $this->files->get($this->providersPath);
        $this->marker = $this->app->storagePath('composer-uninstall-events.log');
        $this->previousDirectory = getcwd();

        $this->composer = new Composer;
        $config = new Config(false);
        $config->merge(['config' => ['vendor-dir' => $this->app->basePath('vendor')]]);
        $this->composer->setConfig($config);

        // Only the child application boots this provider.
        $this->files->replace($this->providersPath, '<?php return [' . ComposerUninstallServiceProvider::class . '::class];');
        chdir($this->app->basePath());
    }

    /**
     * Restore the shared application files and working directory.
     */
    protected function tearDown(): void
    {
        CleanupActions::run(
            fn (): bool => chdir($this->previousDirectory),
            function (): void {
                $this->files->replace($this->providersPath, $this->originalProviders);
            },
            fn (): bool => $this->files->delete($this->marker),
            function (): void {
                parent::tearDown();
            },
        );
    }

    public function testPrePackageUninstallDispatchesEachPackageEvent(): void
    {
        $io = new BufferIO;

        ComposerScripts::prePackageUninstall($this->createPackageEvent('example/first', $io));
        ComposerScripts::prePackageUninstall($this->createPackageEvent('example/second', $io));

        $this->assertSame('', $io->getOutput());
        $this->assertSame(
            'composer_package.example/first:pre_uninstall' . PHP_EOL . 'composer_package.example/second:pre_uninstall' . PHP_EOL,
            $this->files->get($this->marker),
        );
    }

    public function testPrePackageUninstallDoesNotDispatchOutsideDevMode(): void
    {
        $io = new BufferIO;

        ComposerScripts::prePackageUninstall($this->createPackageEvent('example/first', $io, devMode: false));

        $this->assertSame('', $io->getOutput());
        $this->assertFileDoesNotExist($this->marker);
    }

    #[DataProvider('failureVerbosityProvider')]
    public function testPrePackageUninstallContinuesAfterListenerFailure(int $verbosity, string $exceptionOutput): void
    {
        $io = new BufferIO(verbosity: $verbosity);

        ComposerScripts::prePackageUninstall($this->createPackageEvent('example/failing', $io));

        $this->assertSame('composer_package.example/failing:pre_uninstall' . PHP_EOL, $this->files->get($this->marker));
        $this->assertSame(
            'There was an error dispatching or handling the [composer_package.example/failing:pre_uninstall] event. Continuing with package removal...' . PHP_EOL . $exceptionOutput,
            $io->getOutput(),
        );

        $logPath = $this->app->storagePath('logs/hypervel.log');

        $this->assertStringNotContainsString(
            'Package cleanup failed.',
            $this->files->exists($logPath) ? $this->files->get($logPath) : '',
        );
    }

    /**
     * Provide the exception detail shown at each output level.
     *
     * @return array<string, array{int, string}>
     */
    public static function failureVerbosityProvider(): array
    {
        return [
            'normal' => [OutputInterface::VERBOSITY_NORMAL, ''],
            'verbose' => [OutputInterface::VERBOSITY_VERBOSE, 'Exception message: Package cleanup failed.' . PHP_EOL],
        ];
    }

    /**
     * Create the Composer event for a package removal.
     */
    protected function createPackageEvent(string $package, BufferIO $io, bool $devMode = true): PackageEvent
    {
        $operation = new UninstallOperation(new Package($package, '1.0.0.0', '1.0.0'));

        return new PackageEvent(
            PackageEvents::PRE_PACKAGE_UNINSTALL,
            $this->composer,
            $io,
            $devMode,
            new ArrayRepository,
            [$operation],
            $operation,
        );
    }
}
