<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Console\ConfigPublishCommand;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\UsesFrameworkConfiguration;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use Hypervel\Testbench\TestCase;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

#[UsesFrameworkConfiguration]
class ConfigPublishCommandWithoutMergedConfigurationTest extends TestCase
{
    use InteractsWithPublishedFiles;

    protected array $files = [
        'published-config/*.php',
    ];

    protected function setUp(): void
    {
        $files = new Filesystem;

        $this->afterApplicationCreated(function () use ($files): void {
            $files->ensureDirectoryExists($this->app->basePath('published-config'));
            $this->app->useConfigPath($this->app->basePath('published-config'));

            $this->beforeApplicationDestroyed(function () use ($files): void {
                $files->deleteDirectory($this->app->basePath('published-config'));
            });
        });

        parent::setUp();
    }

    public function testItCanPublishConfigFilesWithoutMergedFrameworkConfiguration(): void
    {
        $destination = $this->app->basePath('published-config');
        $expectedConfigs = $this->getExpectedConfigFiles();

        $this->assertSame($destination, config_path());

        foreach (array_keys($expectedConfigs) as $name) {
            $this->assertFileDoesNotExist(config_path("{$name}.php"));
        }

        $this->artisan('config:publish', ['--all' => true])->assertOk();

        foreach ($expectedConfigs as $name => $source) {
            $this->assertFilenameExists("published-config/{$name}.php");
            $this->assertSame(file_get_contents($source), file_get_contents(config_path("{$name}.php")));
        }

        $this->assertSame(config('app.providers'), ServiceProvider::defaultProviders()->toArray());
    }

    /**
     * Get the framework configuration files keyed by name.
     *
     * @return array<string, string>
     */
    private function getExpectedConfigFiles(): array
    {
        $baseConfigPath = dirname((new ReflectionClass(ConfigPublishCommand::class))->getFileName(), 3) . '/config';
        $files = [];

        foreach (Finder::create()->files()->name('*.php')->in($baseConfigPath) as $file) {
            $files[basename($file->getPathname(), '.php')] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }
}
