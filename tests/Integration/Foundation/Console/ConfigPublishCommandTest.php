<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Foundation\Console\ConfigPublishCommand;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

class ConfigPublishCommandTest extends \Hypervel\Testbench\TestCase
{
    use InteractsWithPublishedFiles;

    /**
     * The path to the framework's base configuration files.
     */
    private string $baseConfigPath;

    /**
     * Original config file contents to restore after each test.
     *
     * @var array<string, null|string>
     */
    private array $originalContents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseConfigPath = dirname((new ReflectionClass(ConfigPublishCommand::class))->getFileName(), 3) . '/config';
    }

    protected function tearDown(): void
    {
        foreach ($this->originalContents as $destination => $contents) {
            if ($contents === null) {
                @unlink($destination);
            } else {
                file_put_contents($destination, $contents);
            }
        }

        parent::tearDown();
    }

    public function testItCanPublishSpecificConfigFile()
    {
        $this->preserveConfigFile('cache');

        $this->artisan('config:publish', ['name' => 'cache', '--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain("Published 'cache' configuration file.");

        $this->assertFilenameExists('config/cache.php');
    }

    public function testItPublishesAllConfigFilesWithAllFlag()
    {
        $expectedConfigs = $this->getExpectedConfigNames();

        foreach ($expectedConfigs as $name) {
            $this->preserveConfigFile($name);
        }

        $this->artisan('config:publish', ['--all' => true, '--force' => true])
            ->assertSuccessful();

        foreach ($expectedConfigs as $name) {
            $this->assertFilenameExists("config/{$name}.php");
        }
    }

    public function testItDiscoversCoreFrameworkConfigs()
    {
        $expectedConfigs = $this->getExpectedConfigNames();

        // Every core framework config should be discoverable
        foreach (['app', 'auth', 'cache', 'database', 'logging', 'session', 'view'] as $name) {
            $this->assertContains($name, $expectedConfigs, "Config '{$name}' should be discoverable by config:publish.");
        }
    }

    public function testPublishedContentMatchesSource()
    {
        $name = 'hashing';
        $this->preserveConfigFile($name);

        $this->artisan('config:publish', ['name' => $name, '--force' => true])
            ->assertSuccessful();

        $sourceContent = file_get_contents($this->baseConfigPath . "/{$name}.php");
        $publishedContent = file_get_contents($this->app->configPath("{$name}.php"));

        $this->assertSame($sourceContent, $publishedContent);
    }

    public function testItSkipsExistingConfigWithoutForce()
    {
        $name = 'app';
        $destination = $this->app->configPath("{$name}.php");

        // app.php should already exist in the test app
        if (! is_file($destination)) {
            $this->markTestSkipped('app.php does not exist in test config directory.');
        }

        $this->artisan('config:publish', ['name' => $name])
            ->expectsOutputToContain("The '{$name}' configuration file already exists.");
    }

    public function testItOverwritesExistingConfigWithForce()
    {
        $name = 'app';
        $destination = $this->app->configPath("{$name}.php");

        if (! is_file($destination)) {
            $this->markTestSkipped('app.php does not exist in test config directory.');
        }

        $this->preserveConfigFile($name);

        $this->artisan('config:publish', ['name' => $name, '--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain("Published '{$name}' configuration file.");
    }

    public function testItCanPublishConfigFilesWhenConfiguredWithDontMergeFrameworkConfiguration()
    {
        foreach ([
            'app', 'auth', 'broadcasting', 'cache', 'cors',
            'database', 'filesystems', 'hashing', 'logging',
            'mail', 'queue', 'session', 'view',
        ] as $file) {
            $this->preserveConfigFile($file);
        }

        $this->artisan('config:publish', ['--all' => true, '--force' => true])->assertOk();

        foreach ([
            'app', 'auth', 'broadcasting', 'cache', 'cors',
            'database', 'filesystems', 'hashing', 'logging',
            'mail', 'queue', 'session', 'view',
        ] as $file) {
            $this->assertFilenameExists("config/{$file}.php");
            $this->assertStringContainsString(
                file_get_contents($this->baseConfigPath . "/{$file}.php"),
                file_get_contents(config_path("{$file}.php"))
            );
        }

        $this->assertSame(config('app.providers'), ServiceProvider::defaultProviders()->toArray());
    }

    public function testItFailsWithUnrecognizedConfigFile()
    {
        $this->artisan('config:publish', ['name' => 'nonexistent-config-file'])
            ->expectsOutputToContain('Unrecognized configuration file.')
            ->assertExitCode(1);
    }

    /**
     * Get the expected config file names from the foundation config directory.
     *
     * @return list<string>
     */
    private function getExpectedConfigNames(): array
    {
        $names = [];

        foreach (Finder::create()->files()->name('*.php')->in($this->baseConfigPath) as $file) {
            $names[] = basename($file->getRealPath(), '.php');
        }

        sort($names);

        return $names;
    }

    /**
     * Save the original contents of a config file so it can be restored after the test.
     */
    private function preserveConfigFile(string $name): void
    {
        $destination = $this->app->configPath("{$name}.php");

        $this->originalContents[$destination] = is_file($destination)
            ? file_get_contents($destination)
            : null;
    }
}
