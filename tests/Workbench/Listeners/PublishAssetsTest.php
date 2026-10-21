<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Listeners;

use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Events\ServeCommandStarted;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Workbench\AuthServiceProvider;
use Override;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Hypervel\Testbench\package_path;

class PublishAssetsTest extends TestCase
{
    private const string PUBLISH_COMMAND = 'vendor/bin/testbench vendor:publish --provider="Hypervel\Workbench\AuthServiceProvider" --tag=hypervel-assets';

    private Filesystem $filesystem;

    private BufferedOutput $output;

    private ?string $externalPublicPath = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->output = new BufferedOutput;
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory(base_path('public/vendor/workbench'));

        if ($this->externalPublicPath !== null) {
            $this->filesystem->deleteDirectory($this->externalPublicPath);
        }

        parent::tearDown();
    }

    /**
     * Get the package providers.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    #[Override]
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            AuthServiceProvider::class,
        ];
    }

    #[Test]
    public function itPublishesTheAssetsIntoTheRuntimeSkeletonOwnedByTheProcess(): void
    {
        $this->dispatchServeCommandStarted();

        $this->assertFileEquals(
            package_path('src/workbench/public/build/manifest.json'),
            public_path('vendor/workbench/build/manifest.json'),
        );
        $this->assertSame('', $this->output->fetch());
    }

    #[Test]
    public function itKeepsAssetsThatWereAlreadyPublished(): void
    {
        $this->filesystem->ensureDirectoryExists(public_path('vendor/workbench/build'));
        $this->filesystem->put(public_path('vendor/workbench/build/manifest.json'), '{}');

        $this->dispatchServeCommandStarted();

        $this->assertSame('{}', $this->filesystem->get(public_path('vendor/workbench/build/manifest.json')));
        $this->assertDirectoryDoesNotExist(public_path('vendor/workbench/build/assets'));
    }

    #[Test]
    public function itAsksForExplicitPublishingWhenTheProcessDoesNotOwnTheSkeleton(): void
    {
        $runtimePath = new ReflectionProperty(Bootstrapper::class, 'runtimePath');
        $ownedRuntimePath = $runtimePath->getValue();
        $runtimePath->setValue(null, null);

        try {
            $this->dispatchServeCommandStarted();
        } finally {
            $runtimePath->setValue(null, $ownedRuntimePath);
        }

        $this->assertDirectoryDoesNotExist(public_path('vendor/workbench/build'));
        $this->assertStringContainsString(self::PUBLISH_COMMAND, $this->fetchOutput());
    }

    #[Test]
    #[DefineEnvironment('useExternalPublicPath')]
    public function itDoesNotPublishOutsideTheRuntimeSkeleton(): void
    {
        $this->dispatchServeCommandStarted();

        $this->assertDirectoryDoesNotExist($this->externalPublicPath . '/vendor/workbench/build');
        $this->assertStringContainsString(self::PUBLISH_COMMAND, $this->fetchOutput());
    }

    #[Test]
    public function itFailsWhenTheAssetsCannotBeCopied(): void
    {
        $this->app->instance(Filesystem::class, new class extends Filesystem {
            /**
             * Fail to copy the directory.
             */
            public function copyDirectory(string $directory, string $destination, ?int $options = null): bool
            {
                return false;
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to publish Workbench assets to [' . public_path('vendor/workbench/build') . '].');

        $this->dispatchServeCommandStarted();
    }

    /**
     * Point the application's public path outside the runtime skeleton.
     */
    protected function useExternalPublicPath(ApplicationContract $app): void
    {
        $this->externalPublicPath = ParallelTesting::tempDir('WorkbenchExternalPublicPath');

        (new Filesystem)->ensureDirectoryExists($this->externalPublicPath);

        $app->usePublicPath($this->externalPublicPath);
    }

    /**
     * Dispatch the serve started event through the application's event dispatcher.
     */
    private function dispatchServeCommandStarted(): void
    {
        $input = new ArrayInput([]);
        $output = new OutputStyle($input, $this->output);

        $this->app->make('events')->dispatch(new ServeCommandStarted($input, $output, new Factory($output)));
    }

    /**
     * Get the console output with line wrapping removed.
     */
    private function fetchOutput(): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->output->fetch());
    }
}
